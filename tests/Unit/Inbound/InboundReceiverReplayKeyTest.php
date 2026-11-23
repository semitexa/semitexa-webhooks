<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Webhooks\Application\Service\Inbound\HmacSha256SignatureVerifier;
use Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookReceiver;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookService;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookInboundProcessorInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\CuttingReplayStore;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\KeepingInbox;

/**
 * With the database store the default, the receiver's replay key meets its
 * 191-character column: the key named the endpoint whole, and an endpoint key
 * may be 191 itself, so every request at a long endpoint was cut to one key and
 * all but the first were dropped as replays. And a store that is not shared,
 * which the receiver does not ask, now says so outside dev instead of nothing
 * (tk-webhooks-replay-check-off-with-the-default-store).
 */
final class InboundReceiverReplayKeyTest extends TestCase
{
    private const SECRET = 'partner-secret';

    private string|false $appEnv = false;

    /** The timestamp of the first request a test sends, so a later one can carry the same signed bytes. */
    private ?int $firstTime = null;

    protected function setUp(): void
    {
        $this->appEnv = getenv('APP_ENV');
        self::resetWarned();
    }

    protected function tearDown(): void
    {
        $this->appEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->appEnv);
        self::resetWarned();
    }

    #[Test]
    public function requests_at_a_long_endpoint_are_not_taken_for_replays_of_each_other(): void
    {
        $endpoint = str_repeat('e', 191);
        $receiver = $this->receiver($endpoint, new CuttingReplayStore());
        $processor = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void { $this->calls++; }
        };

        self::assertSame(InboundStatus::Processed, $receiver->receive($this->envelope($endpoint, '{"order":1}', 'evt-1'), $processor)->getStatus());
        self::assertSame(InboundStatus::Processed, $receiver->receive($this->envelope($endpoint, '{"order":2}', 'evt-2'), $processor)->getStatus());

        $captured = $this->envelope($endpoint, '{"order":1}', 'evt-3', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($captured, $processor)->getStatus(), 'the same signed bytes are still a replay');
        self::assertSame(2, $processor->calls);
    }

    #[Test]
    public function a_store_the_workers_do_not_share_is_named_in_production(): void
    {
        putenv('APP_ENV=prod');
        $diagnostics = BootDiagnostics::begin();

        $this->receiver('partner', new InMemoryWebhookReplayStore())->receive($this->envelope('partner', '{}', 'evt-1'));

        $warnings = array_values(array_filter($diagnostics->getWarnings(), static fn ($w): bool => $w->component === 'WebhookReplayStore'));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('in-memory (worker-local)', $warnings[0]->message);
        self::assertStringContainsString('checks no replays', $warnings[0]->message);
    }

    private function envelope(string $endpoint, string $body, string $eventId, ?string $time = null): InboundWebhookEnvelope
    {
        $ts = $time ?? (string) ($this->firstTime ??= time());

        return new InboundWebhookEnvelope(
            endpointKey: $endpoint,
            httpMethod: 'POST',
            requestUri: '/webhooks/' . $endpoint,
            headers: [
                'X-Webhook-Event-Id' => $eventId,
                'X-Webhook-Timestamp' => $ts,
                'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET),
            ],
            rawBody: $body,
            contentType: 'application/json',
        );
    }

    private function receiver(string $endpoint, WebhookReplayStoreInterface $replays): InboundWebhookReceiver
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-in', endpointKey: $endpoint, direction: WebhookDirection::Inbound, providerKey: 'partner',
            enabled: true, tenantId: null, verificationMode: 'hmac-sha256', signingMode: null, secretRef: self::SECRET,
            targetUrl: null, timeoutSeconds: 5, maxAttempts: 5, initialBackoffSeconds: 30, maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null, handlerClass: null, defaultHeaders: null, metadata: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        ));
        $inbox = new KeepingInbox();
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
            'replayStore' => $replays,
        ] as $property => $value) {
            (new \ReflectionProperty(InboundWebhookReceiver::class, $property))->setValue($receiver, $value);
        }

        return $receiver;
    }

    private static function resetWarned(): void
    {
        (new \ReflectionProperty(InboundWebhookReceiver::class, 'warnedNotShared'))->setValue(null, false);
    }
}
