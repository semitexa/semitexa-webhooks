<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Inbound\HmacSha256SignatureVerifier;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookReceiver;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookService;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookInboundProcessorInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

/**
 * The event id that keys the dedupe is not signed. A forged request naming a
 * real id was stored under that id's key, and the genuine delivery that came
 * after it was ignored as a duplicate.
 */
final class InboundReceiverDedupePoisoningTest extends TestCase
{
    private const SECRET = 'partner-secret';

    #[Test]
    public function a_forged_request_does_not_take_the_key_of_the_delivery_it_imitates(): void
    {
        $inbox = new PoisoningInbox();
        $processor = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void { $this->calls++; }
        };
        $receiver = $this->receiver($inbox);

        $forged = $receiver->receive($this->envelope('{"amount":1}', 'evt-42', 'not-the-signature'), $processor);
        $genuine = $receiver->receive($this->envelope('{"amount":100}', 'evt-42'), $processor);

        self::assertSame(InboundStatus::RejectedSignature, $forged->getStatus());
        self::assertSame(InboundStatus::Processed, $genuine->getStatus(), 'the genuine delivery was ignored as a duplicate of the forgery');
        self::assertSame(1, $processor->calls);

        $again = $receiver->receive($this->envelope('{"amount":100}', 'evt-42'), $processor);
        self::assertSame(InboundStatus::DuplicateIgnored, $again->getStatus(), 'a real repeat is still a duplicate');
        self::assertSame(1, $processor->calls);
    }

    /** A captured signed request re-sent under a coming event's id made that event a "duplicate". */
    #[Test]
    public function a_captured_request_under_another_id_does_not_suppress_the_genuine_event(): void
    {
        $processor = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void { $this->calls++; }
        };
        $receiver = $this->receiver(new PoisoningInbox());

        $receiver->receive($this->envelope('{"ping":1}', 'evt-1'), $processor);
        $receiver->receive($this->envelope('{"ping":1}', 'evt-2'), $processor);
        $refund = $receiver->receive($this->envelope('{"refund":42}', 'evt-2'), $processor);

        self::assertSame(InboundStatus::Processed, $refund->getStatus());
    }

    /** Re-sent with fresh event ids, one captured request ran the processor once per id. */
    #[Test]
    public function the_same_signed_bytes_are_processed_once_whatever_event_id_they_carry(): void
    {
        $processor = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void { $this->calls++; }
        };
        $receiver = $this->receiver(new PoisoningInbox(), new SharedReplayStore());
        $captured = $this->envelope('{"refund":42}', 'evt-1');

        $receiver->receive($captured, $processor);
        foreach (['evt-2', 'evt-3'] as $id) {
            $replay = new InboundWebhookEnvelope(
                endpointKey: $captured->endpointKey, httpMethod: $captured->httpMethod, requestUri: $captured->requestUri,
                headers: ['X-Webhook-Event-Id' => $id] + $captured->headers, rawBody: $captured->rawBody, contentType: $captured->contentType,
            );
            self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($replay, $processor)->getStatus());
        }

        self::assertSame(1, $processor->calls);
    }

    /** The row of an outbound endpoint verifies nothing: an unsigned POST to its key was processed. */
    #[Test]
    public function only_an_inbound_endpoint_that_verifies_receives(): void
    {
        foreach ([[WebhookDirection::Outbound, null], [WebhookDirection::Inbound, null], [WebhookDirection::Inbound, '']] as [$direction, $mode]) {
            try {
                $this->receiver(new PoisoningInbox(), direction: $direction, verificationMode: $mode)->receive($this->envelope('{}', 'evt-1', 'none'));
                self::fail('received on ' . $direction->value . ' / ' . var_export($mode, true));
            } catch (\RuntimeException $e) {
                self::assertMatchesRegularExpression('/does not receive|names no verificationMode/', $e->getMessage());
            }
        }
    }

    /** Checked before the delivery was stored or run, the same bytes resent after a failure were dropped for good. */
    #[Test]
    public function an_exact_resend_of_a_failed_delivery_is_processed_again(): void
    {
        $processor = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('handler bug');
                }
            }
        };
        $receiver = $this->receiver(new PoisoningInbox(), new SharedReplayStore());
        $request = $this->envelope('{"refund":42}', 'evt-1');

        self::assertSame(InboundStatus::Failed, $receiver->receive($request, $processor)->getStatus());
        self::assertSame(InboundStatus::Processed, $receiver->receive($request, $processor)->getStatus());
    }

    #[Test]
    public function a_duplicate_is_never_replayed(): void
    {
        $processor = new class implements WebhookInboundProcessorInterface {
            public function process(InboundWebhookEnvelope $envelope): void {}
        };
        $receiver = $this->receiver(new PoisoningInbox(), new SharedReplayStore());
        $captured = $this->envelope('{"refund":42}', 'evt-1');
        $receiver->receive($captured, $processor);
        $copy = $receiver->receive(new InboundWebhookEnvelope(
            endpointKey: $captured->endpointKey, httpMethod: $captured->httpMethod, requestUri: $captured->requestUri,
            headers: ['X-Webhook-Event-Id' => 'evt-2'] + $captured->headers, rawBody: $captured->rawBody, contentType: $captured->contentType,
        ), $processor);

        $this->expectExceptionMessage('duplicate of another');
        $receiver->replay($copy, $processor, force: true);
    }

    #[Test]
    public function a_verified_delivery_replays_through_the_processor_without_its_expired_signature_checked_again(): void
    {
        $inbox = new PoisoningInbox();
        $receiver = $this->receiver($inbox);
        $failing = new class implements WebhookInboundProcessorInterface {
            public function process(InboundWebhookEnvelope $envelope): void { throw new \RuntimeException('handler bug'); }
        };
        $stored = $receiver->receive($this->envelope('{"amount":100}', 'evt-7'), $failing);
        self::assertSame(InboundStatus::Failed, $stored->getStatus());

        $fixed = new class implements WebhookInboundProcessorInterface {
            public ?string $body = null;
            public function process(InboundWebhookEnvelope $envelope): void { $this->body = $envelope->rawBody; }
        };
        $replayed = $receiver->replay($stored, $fixed);

        self::assertSame(InboundStatus::Processed, $replayed->getStatus());
        self::assertSame('{"amount":100}', $fixed->body);
    }

    #[Test]
    public function a_processed_delivery_replays_only_with_force(): void
    {
        $receiver = $this->receiver(new PoisoningInbox());
        $counter = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void { $this->calls++; }
        };
        $processed = $receiver->receive($this->envelope('{"amount":5}', 'evt-9'), $counter);
        self::assertSame(InboundStatus::Processed, $processed->getStatus());

        try {
            $receiver->replay($processed, $counter);
            self::fail('a processed delivery ran its side effects again without force');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already processed', $e->getMessage());
        }
        self::assertSame(1, $counter->calls);

        $receiver->replay($processed, $counter, force: true);
        self::assertSame(2, $counter->calls);
    }

    #[Test]
    public function a_delivery_that_was_never_verified_is_not_replayed(): void
    {
        $receiver = $this->receiver(new PoisoningInbox());
        $forged = $receiver->receive($this->envelope('{"amount":1}', 'evt-8', 'not-the-signature'));

        $this->expectExceptionMessage('never verified');
        $receiver->replay($forged, new class implements WebhookInboundProcessorInterface {
            public function process(InboundWebhookEnvelope $envelope): void {}
        });
    }

    private function envelope(string $body, string $eventId, ?string $signature = null): InboundWebhookEnvelope
    {
        $ts = (string) time();

        return new InboundWebhookEnvelope(
            endpointKey: 'partner',
            httpMethod: 'POST',
            requestUri: '/webhooks/partner',
            headers: [
                'X-Webhook-Event-Id' => $eventId,
                'X-Webhook-Timestamp' => $ts,
                'X-Webhook-Signature' => 'sha256=' . ($signature ?? hash_hmac('sha256', $ts . '.' . $body, self::SECRET)),
            ],
            rawBody: $body,
            contentType: 'application/json',
        );
    }

    private function receiver(PoisoningInbox $inbox, ?WebhookReplayStoreInterface $replays = null, WebhookDirection $direction = WebhookDirection::Inbound, ?string $verificationMode = 'hmac-sha256'): InboundWebhookReceiver
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-in', endpointKey: 'partner', direction: $direction, providerKey: 'partner',
            enabled: true, tenantId: null, verificationMode: $verificationMode, signingMode: null, secretRef: self::SECRET,
            targetUrl: null, timeoutSeconds: 5, maxAttempts: 5, initialBackoffSeconds: 30, maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null, handlerClass: null, defaultHeaders: null, metadata: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        ));
        $service = new InboundWebhookService();
        (new \ReflectionProperty(InboundWebhookService::class, 'inboundRepo'))->setValue($service, $inbox);
        (new \ReflectionProperty(InboundWebhookService::class, 'attemptRepo'))->setValue($service, $this->createStub(WebhookAttemptRepositoryInterface::class));

        $receiver = new InboundWebhookReceiver();
        foreach ([
            'endpointRepo' => $endpoints,
            'verifier' => new HmacSha256SignatureVerifier(),
            'inboxRepo' => $inbox,
            'inboundService' => $service,
            'dedupeKeyFactory' => new InboundDedupeKeyFactory(),
            ...($replays !== null ? ['replayStore' => $replays] : []),
        ] as $property => $value) {
            (new \ReflectionProperty(InboundWebhookReceiver::class, $property))->setValue($receiver, $value);
        }

        return $receiver;
    }
}

/** The MySQL repository's dedupe contract, in memory. */
final class PoisoningInbox implements InboundDeliveryRepositoryInterface
{
    /** @var array<string, InboundDelivery> */
    private array $byKey = [];

    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery
    {
        $existing = $this->byKey[$delivery->getDedupeKey()] ?? null;
        if ($existing === null) {
            return $this->byKey[$delivery->getDedupeKey()] = $delivery;
        }
        if ($existing->getStatus() === InboundStatus::Failed) {
            return $existing;
        }
        $copy = clone $existing;
        $copy->markDuplicateIgnored();

        return $copy;
    }

    public function findById(string $id): ?InboundDelivery { return null; }
    public function save(object $entity): void {}
    public function findByDedupeKey(string $dedupeKey): ?InboundDelivery { return $this->byKey[$dedupeKey] ?? null; }
    public function findByStatus(string $status, int $limit = 50): array { return []; }
    public function deleteTerminalOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null, ?string $tenantId = null): int { return 0; }
    public function countTerminalOlderThan(\DateTimeImmutable $cutoff, ?string $tenantId = null): int { return 0; }
}

/** A replay store the workers share, in memory. */
final class SharedReplayStore implements WebhookReplayStoreInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    public function seen(string $key): bool { return isset($this->seen[$key]); }
    public function markSeen(string $key): void { $this->seen[$key] = true; }
    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;

        return true;
    }
    public function clear(): void { $this->seen = []; }
    public function isShared(): bool { return true; }
    public function diagnosticName(): string { return 'shared-test'; }
}
