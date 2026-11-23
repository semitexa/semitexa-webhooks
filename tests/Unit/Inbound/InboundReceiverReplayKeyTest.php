<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Application\Service\Inbound\HmacSha256SignatureVerifier;
use Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookReceiver;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookService;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookInboundProcessorInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\CuttingReplayStore;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\FlakyReplayStore;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\KeepingInbox;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\LostReplyReplayStore;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\OwnedFaultyReplayStore;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\ReclaimingInbox;

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

    private bool $warnedBefore = false;

    private bool $warnedNoOwnedClaimBefore = false;

    private ?BootDiagnostics $diagnosticsBefore = null;

    /** The timestamp of the first request a test sends, so a later one can carry the same signed bytes. */
    private ?int $firstTime = null;

    // The warned flag and the diagnostics collector are process state: kept
    // as they were and given back (review, semitexa-webhooks#42).
    protected function setUp(): void
    {
        $this->appEnv = getenv('APP_ENV');
        $this->warnedBefore = (bool) self::warned()->getValue();
        $current = self::collector()->getValue();
        $this->diagnosticsBefore = $current instanceof BootDiagnostics ? $current : null;
        self::warned()->setValue(null, false);
        $this->warnedNoOwnedClaimBefore = (bool) self::warnedNoOwnedClaim()->getValue();
        self::warnedNoOwnedClaim()->setValue(null, false);
    }

    protected function tearDown(): void
    {
        $this->appEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->appEnv);
        self::warned()->setValue(null, $this->warnedBefore);
        self::warnedNoOwnedClaim()->setValue(null, $this->warnedNoOwnedClaimBefore);
        self::collector()->setValue(null, $this->diagnosticsBefore);
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

    /** A store that fails after the row is stored left it Received: the retry was its duplicate (review, semitexa-webhooks#42). */
    #[Test]
    public function a_delivery_whose_replay_check_failed_is_processed_on_retry_and_claims_then(): void
    {
        $inbox = new ReclaimingInbox();
        $receiver = $this->receiver('partner', new FlakyReplayStore(), $inbox);
        $processor = new class implements WebhookInboundProcessorInterface {
            public int $calls = 0;
            public function process(InboundWebhookEnvelope $envelope): void { $this->calls++; }
        };
        $request = $this->envelope('partner', '{"refund":42}', 'evt-1');

        try {
            $receiver->receive($request, $processor);
            self::fail('the replay store failed and the receiver said nothing');
        } catch (\RuntimeException $e) {
            self::assertSame('replay store is down', $e->getMessage());
        }
        self::assertSame(0, $processor->calls);

        self::assertSame(InboundStatus::Processed, $receiver->receive($request, $processor)->getStatus(), 'the retry is processed');
        $replay = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($replay, $processor)->getStatus(), 'the retry made the claim');
        self::assertSame(1, $processor->calls);
    }

    /**
     * The retry of a failed delivery, signed again with a new timestamp, was
     * handed back by the inbox and processed with no claim: its bytes, captured,
     * ran once more under another event id
     * (tk-webhooks-reclaimed-delivery-retry-is-not-claimed).
     */
    #[Test]
    public function a_failed_deliverys_re_signed_retry_claims_its_bytes(): void
    {
        $receiver = $this->receiver('partner', new CuttingReplayStore(), new ReclaimingInbox());
        $processor = $this->processor(failures: 1);

        self::assertSame(InboundStatus::Failed, $receiver->receive($this->envelope('partner', '{"refund":42}', 'evt-1'), $processor)->getStatus());
        $later = (string) ($this->firstTime + 1);
        self::assertSame(InboundStatus::Processed, $receiver->receive($this->envelope('partner', '{"refund":42}', 'evt-1', time: $later), $processor)->getStatus(), 'the retry is processed');

        $captured = $this->envelope('partner', '{"refund":42}', 'evt-2', time: $later);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($captured, $processor)->getStatus(), 'the retry claimed its bytes');
        self::assertSame(2, $processor->calls);
    }

    /**
     * A row that never made its claim was known by its last error, and the
     * inbox writes that column over when it takes an abandoned row: the retry
     * then ran with no claim.
     */
    #[Test]
    public function a_retry_claims_whatever_the_rows_last_error_says(): void
    {
        $inbox = new ReclaimingInbox();
        $receiver = $this->receiver('partner', new FlakyReplayStore(), $inbox);
        $processor = $this->processor();
        $request = $this->envelope('partner', '{"refund":42}', 'evt-1');

        try {
            $receiver->receive($request, $processor);
            self::fail('the replay store failed and the receiver said nothing');
        } catch (\RuntimeException $e) {
            self::assertSame('replay store is down', $e->getMessage());
        }
        $row = $inbox->findByDedupeKey((new InboundDedupeKeyFactory())->generate('partner', 'partner', 'evt-1', '{"refund":42}', null));
        self::assertNotNull($row, 'the row was stored before the store failed');
        self::assertSame(InboundStatus::Failed, $row->getStatus());
        // As the inbox does to a row left received for 15 minutes.
        $row->markFailed('Abandoned before processing.');

        self::assertSame(InboundStatus::Processed, $receiver->receive($request, $processor)->getStatus(), 'the retry is processed');
        $captured = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($captured, $processor)->getStatus(), 'the retry made the claim');
        self::assertSame(1, $processor->calls);
    }

    /**
     * A claim that reached the store while its answer did not (the worker
     * died, the connection dropped): the retry found its own key and was
     * dropped as a replay, so the event never ran.
     */
    #[Test]
    public function a_delivery_that_claimed_and_died_is_processed_on_retry(): void
    {
        $receiver = $this->receiver('partner', new LostReplyReplayStore(), new ReclaimingInbox());
        $processor = $this->processor();
        $request = $this->envelope('partner', '{"refund":42}', 'evt-1');

        try {
            $receiver->receive($request, $processor);
            self::fail('the replay store failed and the receiver said nothing');
        } catch (\RuntimeException $e) {
            self::assertSame('the reply was lost', $e->getMessage());
        }
        self::assertSame(0, $processor->calls);

        self::assertSame(InboundStatus::Processed, $receiver->receive($request, $processor)->getStatus(), 'the claim is its own');
        self::assertSame(1, $processor->calls);

        $captured = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($captured, $processor)->getStatus(), 'and nobody else\'s');
        self::assertSame(1, $processor->calls);
    }

    /**
     * The mark was the start of the row's last error, which a processor writes
     * too: one failing with those words made its own exact resend claim again,
     * lose to itself and be dropped.
     */
    #[Test]
    public function a_processor_error_does_not_decide_whether_the_retry_is_a_replay(): void
    {
        $receiver = $this->receiver('partner', new CuttingReplayStore(), new ReclaimingInbox());
        $processor = $this->processor(failures: 1, error: 'replay check failed: the ledger is closed');
        $request = $this->envelope('partner', '{"refund":42}', 'evt-1');

        $failed = $receiver->receive($request, $processor);
        self::assertSame(InboundStatus::Failed, $failed->getStatus());
        self::assertSame('replay check failed: the ledger is closed', $failed->getLastError());

        self::assertSame(InboundStatus::Processed, $receiver->receive($request, $processor)->getStatus(), 'an exact resend of a failed delivery is processed again');
        self::assertSame(2, $processor->calls);
    }

    /** What must stay: one captured request under another event id runs once. */
    #[Test]
    public function the_same_signed_bytes_under_another_event_id_are_a_replay(): void
    {
        $inbox = new KeepingInbox();
        $receiver = $this->receiver('partner', new CuttingReplayStore(), $inbox);
        $processor = $this->processor();

        self::assertSame(InboundStatus::Processed, $receiver->receive($this->envelope('partner', '{"refund":42}', 'evt-1'), $processor)->getStatus());
        $captured = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($captured, $processor)->getStatus());
        self::assertCount(2, $inbox->byKey, 'two deliveries, one run');
        self::assertSame(1, $processor->calls);
    }

    /**
     * With two keys, a copy under another event id whose claim call failed had
     * its owner key written already: on its retry the lost claim looked like
     * its own, and it ran the bytes another delivery holds. The claim names
     * its winner now (review, semitexa-webhooks#43).
     */
    #[Test]
    public function a_copy_whose_claim_call_failed_is_still_a_replay_on_its_retry(): void
    {
        $receiver = $this->receiver('partner', new OwnedFaultyReplayStore(failOn: 2, afterWrite: false), new ReclaimingInbox());
        $processor = $this->processor();

        self::assertSame(InboundStatus::Processed, $receiver->receive($this->envelope('partner', '{"refund":42}', 'evt-1'), $processor)->getStatus());

        $copy = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        try {
            $receiver->receive($copy, $processor);
            self::fail('the replay store failed and the receiver said nothing');
        } catch (\RuntimeException $e) {
            self::assertSame('replay store is down', $e->getMessage());
        }

        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($copy, $processor)->getStatus(), 'the claim is the first delivery\'s');
        self::assertSame(1, $processor->calls);
    }

    /** The other side of it: a winner whose answer was lost is not dropped, and its claim holds. */
    #[Test]
    public function a_winner_that_lost_its_claim_reply_is_processed_on_retry_by_the_claim_that_names_it(): void
    {
        $store = new OwnedFaultyReplayStore(failOn: 1, afterWrite: true);
        $receiver = $this->receiver('partner', $store, new ReclaimingInbox());
        $processor = $this->processor();
        $request = $this->envelope('partner', '{"refund":42}', 'evt-1');

        try {
            $receiver->receive($request, $processor);
            self::fail('the replay store failed and the receiver said nothing');
        } catch (\RuntimeException $e) {
            self::assertSame('the reply was lost', $e->getMessage());
        }
        self::assertSame(0, $processor->calls);

        self::assertSame(InboundStatus::Processed, $receiver->receive($request, $processor)->getStatus(), 'the claim is its own');
        $copy = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($copy, $processor)->getStatus(), 'and nobody else\'s');
        self::assertSame(1, $processor->calls);
        self::assertSame(3, $store->ownedClaims, 'every arrival made the one claim that names its owner');
    }

    /**
     * Deployed before orm:sync, the table has no owner column: the claim
     * failing would fail every delivery until the sync. The two keys stand in,
     * and it is said once.
     */
    #[Test]
    public function a_table_with_no_owner_column_yet_falls_back_to_two_keys_and_says_so_once(): void
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(sprintf(
            'CREATE TABLE %s (replay_key VARCHAR(191) NOT NULL PRIMARY KEY, first_seen_at DATETIME NOT NULL, expires_at DATETIME NULL)',
            MySqlWebhookReplayStore::TABLE,
        ));
        $diagnostics = BootDiagnostics::begin();
        $receiver = $this->receiver('partner', new MySqlWebhookReplayStore($orm), new ReclaimingInbox());
        $processor = $this->processor();

        self::assertSame(InboundStatus::Processed, $receiver->receive($this->envelope('partner', '{"refund":42}', 'evt-1'), $processor)->getStatus());
        $copy = $this->envelope('partner', '{"refund":42}', 'evt-2', time: (string) $this->firstTime);
        self::assertSame(InboundStatus::DuplicateIgnored, $receiver->receive($copy, $processor)->getStatus(), 'the two keys still catch a replay');
        self::assertSame(1, $processor->calls);

        $warnings = array_values(array_filter($diagnostics->getWarnings(), static fn ($w): bool => $w->component === 'WebhookReplayStore'));
        self::assertCount(1, $warnings, 'said once, not per delivery');
        self::assertStringContainsString('orm:sync', $warnings[0]->message);
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

    /** A processor that counts its calls and fails the first $failures of them. */
    private function processor(int $failures = 0, string $error = 'the ledger is closed'): WebhookInboundProcessorInterface
    {
        return new class($failures, $error) implements WebhookInboundProcessorInterface {
            public int $calls = 0;

            public function __construct(private int $failures, private readonly string $error) {}

            public function process(InboundWebhookEnvelope $envelope): void
            {
                $this->calls++;
                if ($this->failures-- > 0) {
                    throw new \RuntimeException($this->error);
                }
            }
        };
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

    private function receiver(string $endpoint, WebhookReplayStoreInterface $replays, ?InboundDeliveryRepositoryInterface $inbox = null): InboundWebhookReceiver
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-in', endpointKey: $endpoint, direction: WebhookDirection::Inbound, providerKey: 'partner',
            enabled: true, tenantId: null, verificationMode: 'hmac-sha256', signingMode: null, secretRef: self::SECRET,
            targetUrl: null, timeoutSeconds: 5, maxAttempts: 5, initialBackoffSeconds: 30, maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null, handlerClass: null, defaultHeaders: null, metadata: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        ));
        $inbox ??= new KeepingInbox();
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

    private static function warned(): \ReflectionProperty
    {
        return new \ReflectionProperty(InboundWebhookReceiver::class, 'warnedNotShared');
    }

    private static function warnedNoOwnedClaim(): \ReflectionProperty
    {
        return new \ReflectionProperty(InboundWebhookReceiver::class, 'warnedNoOwnedClaim');
    }

    private static function collector(): \ReflectionProperty
    {
        return new \ReflectionProperty(BootDiagnostics::class, 'current');
    }
}
