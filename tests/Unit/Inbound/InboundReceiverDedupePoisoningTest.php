<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Inbound\HmacSha256SignatureVerifier;
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

    private function receiver(PoisoningInbox $inbox): InboundWebhookReceiver
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-in', endpointKey: 'partner', direction: WebhookDirection::Inbound, providerKey: 'partner',
            enabled: true, tenantId: null, verificationMode: 'hmac-sha256', signingMode: null, secretRef: self::SECRET,
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
