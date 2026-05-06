<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\InboundDeliveryRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\OutboundDeliveryRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\WebhookAttemptRepository;
use Semitexa\Webhooks\Application\Service\WebhookRetentionService;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;

/**
 * Integration coverage for the operator-facing cleanup orchestration.
 *
 * The contracts under test are operational, not domain-logical:
 *   - status-aware deletes never touch live work (Pending / RetryScheduled
 *     / Delivering on outbound; Received / Verified / Processing on inbound)
 *   - replay-key cleanup preserves NULL-expiry and future-expiry rows
 *   - active leases on outbound terminal rows still keep them safe
 *     (defense-in-depth)
 *   - dry-run is a true no-op and reports the same row count as the apply
 *     would delete
 *   - --batch-size caps per-call deletes and the rest survives for the
 *     next run
 *
 * Tests run against real MySQL (DB_HOST). They DROP and CREATE every
 * involved table in setUp so a leak from a prior test never poisons the
 * next.
 */
final class WebhookCleanupIntegrationTest extends TestCase
{
    private OrmManager $orm;
    private WebhookRetentionService $retention;
    private MySqlWebhookReplayStore $replayStore;
    private InboundDeliveryRepository $inboxRepo;
    private OutboundDeliveryRepository $outboxRepo;
    private WebhookAttemptRepository $attemptRepo;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — integration test requires real MySQL');
        }

        try {
            $container = ContainerFactory::get();
            $this->orm = $container->get(OrmManager::class);
            $this->orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $this->resetTables();

        // Build the service with explicit property injection so we control
        // the WebhookConfig (need a known retention window for assertions).
        $this->inboxRepo = $container->get(InboundDeliveryRepository::class);
        $this->outboxRepo = $container->get(OutboundDeliveryRepository::class);
        $this->attemptRepo = $container->get(WebhookAttemptRepository::class);

        $svc = new WebhookRetentionService();
        $reflection = new \ReflectionClass(WebhookRetentionService::class);

        $cfg = $reflection->getProperty('config');
        $cfg->setAccessible(true);
        $cfg->setValue($svc, WebhookConfig::withOverrides(retentionDays: 7));

        $inbox = $reflection->getProperty('inboxRepo');
        $inbox->setAccessible(true);
        $inbox->setValue($svc, $this->inboxRepo);

        $outbox = $reflection->getProperty('outboxRepo');
        $outbox->setAccessible(true);
        $outbox->setValue($svc, $this->outboxRepo);

        $att = $reflection->getProperty('attemptRepo');
        $att->setAccessible(true);
        $att->setValue($svc, $this->attemptRepo);

        $this->retention = $svc;
        $this->replayStore = new MySqlWebhookReplayStore($this->orm);
    }

    protected function tearDown(): void
    {
        if (!isset($this->orm)) {
            return;
        }
        try {
            $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS `webhook_attempts`');
            $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS `webhook_inbox`');
            $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS `webhook_outbox`');
            $this->orm->getAdapter()->execute(
                sprintf('DROP TABLE IF EXISTS `%s`', MySqlWebhookReplayStore::TABLE),
            );
        } catch (\Throwable) {
            // best effort
        }
    }

    private function resetTables(): void
    {
        $a = $this->orm->getAdapter();

        $a->execute(sprintf('DROP TABLE IF EXISTS `%s`', MySqlWebhookReplayStore::TABLE));
        $a->execute(sprintf(
            'CREATE TABLE `%s` (
                replay_key VARCHAR(191) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                PRIMARY KEY (replay_key),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            MySqlWebhookReplayStore::TABLE,
        ));

        $a->execute('DROP TABLE IF EXISTS `webhook_inbox`');
        $a->execute(<<<'SQL'
CREATE TABLE `webhook_inbox` (
    id BINARY(16) NOT NULL,
    endpoint_definition_id BINARY(16) NOT NULL,
    provider_key VARCHAR(64) NOT NULL,
    endpoint_key VARCHAR(191) NOT NULL,
    tenant_id VARCHAR(64) NULL,
    provider_event_id VARCHAR(255) NULL,
    dedupe_key VARCHAR(255) NOT NULL,
    signature_status VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    content_type VARCHAR(128) NULL,
    http_method VARCHAR(16) NOT NULL,
    request_uri VARCHAR(2048) NOT NULL,
    headers_json LONGTEXT NULL,
    raw_body LONGTEXT NULL,
    raw_body_sha256 CHAR(64) NOT NULL,
    parsed_event_type VARCHAR(191) NULL,
    first_received_at DATETIME NOT NULL,
    last_received_at DATETIME NOT NULL,
    processing_started_at DATETIME NULL,
    processed_at DATETIME NULL,
    failed_at DATETIME NULL,
    duplicate_count INT NOT NULL,
    last_error LONGTEXT NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NULL,
    updated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_inbox_dedupe_key (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        $a->execute('DROP TABLE IF EXISTS `webhook_outbox`');
        $a->execute(<<<'SQL'
CREATE TABLE `webhook_outbox` (
    id BINARY(16) NOT NULL,
    endpoint_definition_id BINARY(16) NOT NULL,
    endpoint_key VARCHAR(191) NOT NULL,
    provider_key VARCHAR(64) NOT NULL,
    tenant_id VARCHAR(64) NULL,
    event_type VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL,
    idempotency_key VARCHAR(255) NULL,
    payload_json LONGTEXT NOT NULL,
    headers_json LONGTEXT NULL,
    signed_headers_json LONGTEXT NULL,
    next_attempt_at DATETIME(6) NOT NULL,
    last_attempt_at DATETIME(6) NULL,
    delivered_at DATETIME(6) NULL,
    attempt_count INT NOT NULL,
    max_attempts INT NOT NULL,
    initial_backoff_seconds INT NOT NULL,
    max_backoff_seconds INT NOT NULL,
    lease_owner VARCHAR(191) NULL,
    lease_expires_at DATETIME(6) NULL,
    last_response_status INT NULL,
    last_response_headers_json LONGTEXT NULL,
    last_response_body LONGTEXT NULL,
    last_error LONGTEXT NULL,
    source_ref VARCHAR(255) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_outbox_endpoint_idempotency (endpoint_definition_id, idempotency_key),
    INDEX idx_status_next_attempt (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        $a->execute('DROP TABLE IF EXISTS `webhook_attempts`');
        $a->execute(<<<'SQL'
CREATE TABLE `webhook_attempts` (
    id BINARY(16) NOT NULL,
    direction VARCHAR(16) NOT NULL,
    inbox_id BINARY(16) NULL,
    outbox_id BINARY(16) NULL,
    event_type VARCHAR(64) NOT NULL,
    attempt_number INT NULL,
    status_before VARCHAR(32) NULL,
    status_after VARCHAR(32) NULL,
    worker_id VARCHAR(128) NULL,
    http_status INT NULL,
    message VARCHAR(512) NULL,
    details_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    // ------------------------------------------------------------------
    //  Replay-key cleanup
    // ------------------------------------------------------------------

    #[Test]
    public function replay_key_cleanup_deletes_expired_and_preserves_null_and_future(): void
    {
        $past = (new \DateTimeImmutable('-2 hours'));
        $future = (new \DateTimeImmutable('+2 hours'));

        $this->insertReplayKey('key-expired-1', $past);
        $this->insertReplayKey('key-expired-2', $past);
        $this->insertReplayKey('key-future', $future);
        $this->insertReplayKey('key-permanent', null);

        $deleted = $this->replayStore->cleanupExpired();

        self::assertSame(2, $deleted, 'two expired keys must be removed');
        self::assertFalse($this->replayKeyExists('key-expired-1'));
        self::assertFalse($this->replayKeyExists('key-expired-2'));
        self::assertTrue($this->replayKeyExists('key-future'), 'future expiry must survive');
        self::assertTrue($this->replayKeyExists('key-permanent'), 'NULL expiry (intentionally permanent) must survive');
    }

    #[Test]
    public function replay_key_count_matches_delete_count(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'));
        $this->insertReplayKey('key-count-a', $past);
        $this->insertReplayKey('key-count-b', $past);
        $this->insertReplayKey('key-count-survive', null);

        $eligible = $this->replayStore->countExpired();
        self::assertSame(2, $eligible);

        $deleted = $this->replayStore->cleanupExpired();
        self::assertSame($eligible, $deleted, 'count() preview must match delete() actual');
    }

    #[Test]
    public function replay_key_cleanup_respects_batch_size(): void
    {
        $past = new \DateTimeImmutable('-1 hour');
        for ($i = 0; $i < 5; $i++) {
            $this->insertReplayKey('key-batch-' . $i, $past);
        }

        $deleted = $this->replayStore->cleanupExpired(null, 2);
        self::assertSame(2, $deleted);

        $remaining = $this->replayStore->countExpired();
        self::assertSame(3, $remaining, 'unbatched remainder must still be eligible for next run');
    }

    // ------------------------------------------------------------------
    //  Outbound: terminal-only deletion
    // ------------------------------------------------------------------

    #[Test]
    public function outbound_cleanup_deletes_terminal_old_rows_only(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        $now = new \DateTimeImmutable();

        // Old + terminal — must be deleted.
        $this->insertOutbox('out-old-delivered', OutboundStatus::Delivered, $old);
        $this->insertOutbox('out-old-failed', OutboundStatus::Failed, $old);
        $this->insertOutbox('out-old-cancelled', OutboundStatus::Cancelled, $old);

        // Old + live work — MUST survive.
        $this->insertOutbox('out-old-pending', OutboundStatus::Pending, $old);
        $this->insertOutbox('out-old-retry', OutboundStatus::RetryScheduled, $old);
        $this->insertOutbox('out-old-delivering', OutboundStatus::Delivering, $old);

        // Recent + terminal — must survive (within retention window).
        $this->insertOutbox('out-new-delivered', OutboundStatus::Delivered, $now);

        $result = $this->retention->purge(batchSize: 0, dryRun: false);

        self::assertSame(3, $result['outbox'], 'only old terminal rows deleted');
        self::assertSame(0, $this->outboxCountWhereId('out-old-delivered'));
        self::assertSame(0, $this->outboxCountWhereId('out-old-failed'));
        self::assertSame(0, $this->outboxCountWhereId('out-old-cancelled'));
        self::assertSame(1, $this->outboxCountWhereId('out-old-pending'), 'pending preserved');
        self::assertSame(1, $this->outboxCountWhereId('out-old-retry'), 'retry_scheduled preserved');
        self::assertSame(1, $this->outboxCountWhereId('out-old-delivering'), 'delivering preserved');
        self::assertSame(1, $this->outboxCountWhereId('out-new-delivered'), 'recent delivered preserved');
    }

    #[Test]
    public function outbound_cleanup_preserves_terminal_rows_with_active_lease(): void
    {
        // A delivered row with an unexpired lease (unusual but possible if a
        // worker just finalized and the lease release hasn't run yet) must
        // not be deleted out from under the worker.
        $old = new \DateTimeImmutable('-30 days');
        $futureLease = (new \DateTimeImmutable('+10 minutes'));

        $this->insertOutbox('out-old-delivered-leased', OutboundStatus::Delivered, $old, leaseExpiresAt: $futureLease);
        $this->insertOutbox('out-old-delivered-no-lease', OutboundStatus::Delivered, $old);

        $result = $this->retention->purge(batchSize: 0, dryRun: false);

        self::assertSame(1, $result['outbox']);
        self::assertSame(1, $this->outboxCountWhereId('out-old-delivered-leased'), 'active-lease row preserved');
        self::assertSame(0, $this->outboxCountWhereId('out-old-delivered-no-lease'));
    }

    #[Test]
    public function outbound_count_terminal_matches_delete_terminal(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        $this->insertOutbox('out-cnt-a', OutboundStatus::Delivered, $old);
        $this->insertOutbox('out-cnt-b', OutboundStatus::Failed, $old);
        $this->insertOutbox('out-cnt-c', OutboundStatus::Pending, $old);

        $cutoff = new \DateTimeImmutable('-7 days');
        $eligible = $this->outboxRepo->countTerminalOlderThan($cutoff);
        self::assertSame(2, $eligible);

        $deleted = $this->outboxRepo->deleteTerminalOlderThan($cutoff);
        self::assertSame($eligible, $deleted);
    }

    // ------------------------------------------------------------------
    //  Inbound: terminal-only deletion
    // ------------------------------------------------------------------

    #[Test]
    public function inbound_cleanup_deletes_terminal_old_rows_only(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        $now = new \DateTimeImmutable();

        // Old + terminal — must be deleted.
        $this->insertInbox('in-old-processed', InboundStatus::Processed, $old);
        $this->insertInbox('in-old-failed', InboundStatus::Failed, $old);
        $this->insertInbox('in-old-rejected', InboundStatus::RejectedSignature, $old);
        $this->insertInbox('in-old-dup', InboundStatus::DuplicateIgnored, $old);

        // Old + live work — MUST survive.
        $this->insertInbox('in-old-received', InboundStatus::Received, $old);
        $this->insertInbox('in-old-verified', InboundStatus::Verified, $old);
        $this->insertInbox('in-old-processing', InboundStatus::Processing, $old);

        // Recent + terminal — must survive.
        $this->insertInbox('in-new-processed', InboundStatus::Processed, $now);

        $result = $this->retention->purge(batchSize: 0, dryRun: false);

        self::assertSame(4, $result['inbox']);
        self::assertSame(0, $this->inboxCountWhereDedupe('in-old-processed'));
        self::assertSame(0, $this->inboxCountWhereDedupe('in-old-failed'));
        self::assertSame(0, $this->inboxCountWhereDedupe('in-old-rejected'));
        self::assertSame(0, $this->inboxCountWhereDedupe('in-old-dup'));
        self::assertSame(1, $this->inboxCountWhereDedupe('in-old-received'), 'received preserved');
        self::assertSame(1, $this->inboxCountWhereDedupe('in-old-verified'), 'verified preserved');
        self::assertSame(1, $this->inboxCountWhereDedupe('in-old-processing'), 'processing preserved');
        self::assertSame(1, $this->inboxCountWhereDedupe('in-new-processed'), 'recent processed preserved');
    }

    // ------------------------------------------------------------------
    //  Attempts cleanup
    // ------------------------------------------------------------------

    #[Test]
    public function attempt_cleanup_deletes_old_rows_unconditionally(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        $now = new \DateTimeImmutable();

        $this->insertAttempt('att-old-1', $old);
        $this->insertAttempt('att-old-2', $old);
        $this->insertAttempt('att-recent', $now);

        $result = $this->retention->purge(batchSize: 0, dryRun: false);

        self::assertSame(2, $result['attempts']);
        self::assertSame(1, $this->attemptCount(), 'recent attempt preserved');
    }

    // ------------------------------------------------------------------
    //  Dry-run
    // ------------------------------------------------------------------

    #[Test]
    public function dry_run_reports_what_would_delete_and_changes_no_rows(): void
    {
        $old = new \DateTimeImmutable('-30 days');

        $this->insertOutbox('dry-out-a', OutboundStatus::Delivered, $old);
        $this->insertOutbox('dry-out-b', OutboundStatus::Failed, $old);
        $this->insertInbox('dry-in-a', InboundStatus::Processed, $old);
        $this->insertAttempt('dry-att-a', $old);

        $result = $this->retention->purge(batchSize: 0, dryRun: true);

        self::assertTrue($result['dry_run']);
        self::assertSame(2, $result['outbox']);
        self::assertSame(1, $result['inbox']);
        self::assertSame(1, $result['attempts']);

        // Nothing actually deleted.
        self::assertSame(2, $this->outboxTotal());
        self::assertSame(1, $this->inboxTotal());
        self::assertSame(1, $this->attemptCount());
    }

    // ------------------------------------------------------------------
    //  Batch size
    // ------------------------------------------------------------------

    #[Test]
    public function batch_size_limits_outbound_deletes_per_call(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        for ($i = 0; $i < 5; $i++) {
            $this->insertOutbox('batch-out-' . $i, OutboundStatus::Delivered, $old);
        }

        $result = $this->retention->purge(batchSize: 2, dryRun: false);
        self::assertSame(2, $result['outbox'], 'first call deletes batch_size rows');
        self::assertSame(3, $this->outboxTotal(), 'remainder survives for next run');

        // Second call drains another batch.
        $second = $this->retention->purge(batchSize: 2, dryRun: false);
        self::assertSame(2, $second['outbox']);
        self::assertSame(1, $this->outboxTotal());
    }

    #[Test]
    public function batch_size_zero_means_unbounded(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        for ($i = 0; $i < 7; $i++) {
            $this->insertAttempt('att-unbounded-' . $i, $old);
        }

        $result = $this->retention->purge(batchSize: 0, dryRun: false);
        self::assertSame(7, $result['attempts']);
        self::assertSame(0, $this->attemptCount());
    }

    #[Test]
    public function purge_summary_carries_cutoff_retention_and_mode(): void
    {
        $result = $this->retention->purge(batchSize: 100, dryRun: true);

        self::assertSame(7, $result['retention_days']);
        self::assertSame(100, $result['batch_size']);
        self::assertTrue($result['dry_run']);
        self::assertNotEmpty($result['cutoff']);
    }

    #[Test]
    public function purge_rejects_non_positive_retention_override(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->retention->purge(batchSize: 0, dryRun: true, retentionDaysOverride: 0);
    }

    // ------------------------------------------------------------------
    //  Tenant-scoped cleanup
    // ------------------------------------------------------------------

    #[Test]
    public function tenant_scoped_outbox_cleanup_only_deletes_target_tenant_rows(): void
    {
        $old = new \DateTimeImmutable('-30 days');

        $this->insertOutbox('out-tenant-a', OutboundStatus::Delivered, $old, tenantId: 'tenant-a');
        $this->insertOutbox('out-tenant-b', OutboundStatus::Delivered, $old, tenantId: 'tenant-b');
        $this->insertOutbox('out-no-tenant', OutboundStatus::Delivered, $old);

        $result = $this->retention->purge(batchSize: 0, dryRun: false, retentionDaysOverride: null, tenantId: 'tenant-a');

        self::assertSame(1, $result['outbox'], 'only tenant-a row deleted');
        self::assertSame(0, $this->outboxCountWhereId('out-tenant-a'));
        self::assertSame(1, $this->outboxCountWhereId('out-tenant-b'), 'tenant-b preserved');
        self::assertSame(1, $this->outboxCountWhereId('out-no-tenant'), 'NULL tenant_id preserved');
    }

    #[Test]
    public function tenant_scoped_inbox_cleanup_only_deletes_target_tenant_rows(): void
    {
        $old = new \DateTimeImmutable('-30 days');

        $this->insertInbox('in-tenant-a', InboundStatus::Processed, $old, tenantId: 'tenant-a');
        $this->insertInbox('in-tenant-b', InboundStatus::Processed, $old, tenantId: 'tenant-b');
        $this->insertInbox('in-no-tenant', InboundStatus::Processed, $old);

        $result = $this->retention->purge(batchSize: 0, dryRun: false, retentionDaysOverride: null, tenantId: 'tenant-a');

        self::assertSame(1, $result['inbox']);
        self::assertSame(0, $this->inboxCountWhereDedupe('in-tenant-a'));
        self::assertSame(1, $this->inboxCountWhereDedupe('in-tenant-b'), 'tenant-b preserved');
        self::assertSame(1, $this->inboxCountWhereDedupe('in-no-tenant'), 'NULL tenant_id preserved');
    }

    #[Test]
    public function tenant_scoped_run_skips_attempts_cleanup(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        $this->insertAttempt('att-old-1', $old);
        $this->insertAttempt('att-old-2', $old);

        $result = $this->retention->purge(batchSize: 0, dryRun: false, retentionDaysOverride: null, tenantId: 'tenant-a');

        self::assertNull($result['attempts'], 'tenant-scoped run reports null for attempts');
        self::assertSame(2, $this->attemptCount(), 'attempts table untouched in tenant-scoped run');
    }

    #[Test]
    public function tenant_scoped_replay_key_cleanup_only_targets_tenant_prefixed_keys(): void
    {
        $past = new \DateTimeImmutable('-1 hour');

        // Build keys via the factory so the test pins the same prefix the
        // production write path emits.
        $this->insertReplayKey(\Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory::compose('demo:signed', 'evt-1', 'tenant-a'), $past);
        $this->insertReplayKey(\Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory::compose('demo:signed', 'evt-2', 'tenant-a'), $past);
        $this->insertReplayKey(\Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory::compose('demo:signed', 'evt-3', 'tenant-b'), $past);
        $this->insertReplayKey(\Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory::compose('demo:signed', 'evt-4', null), $past);

        $deleted = $this->replayStore->cleanupExpired(null, null, 'tenant-a');

        self::assertSame(2, $deleted, 'only tenant-a expired keys removed');
        self::assertFalse($this->replayKeyExists('tenant:tenant-a:demo:signed:evt-1'));
        self::assertFalse($this->replayKeyExists('tenant:tenant-a:demo:signed:evt-2'));
        self::assertTrue($this->replayKeyExists('tenant:tenant-b:demo:signed:evt-3'), 'tenant-b key preserved');
        self::assertTrue($this->replayKeyExists('demo:signed:evt-4'), 'untenanted (legacy) key preserved');
    }

    #[Test]
    public function tenant_scoped_dry_run_returns_per_tenant_counts(): void
    {
        $old = new \DateTimeImmutable('-30 days');
        $this->insertOutbox('dry-tenant-a-1', OutboundStatus::Delivered, $old, tenantId: 'tenant-a');
        $this->insertOutbox('dry-tenant-a-2', OutboundStatus::Failed, $old, tenantId: 'tenant-a');
        $this->insertOutbox('dry-tenant-b-1', OutboundStatus::Delivered, $old, tenantId: 'tenant-b');
        $this->insertInbox('dry-in-a', InboundStatus::Processed, $old, tenantId: 'tenant-a');
        $this->insertInbox('dry-in-b', InboundStatus::Processed, $old, tenantId: 'tenant-b');

        $a = $this->retention->purge(batchSize: 0, dryRun: true, retentionDaysOverride: null, tenantId: 'tenant-a');
        self::assertSame(2, $a['outbox']);
        self::assertSame(1, $a['inbox']);
        self::assertSame('tenant-a', $a['tenant_id']);

        $b = $this->retention->purge(batchSize: 0, dryRun: true, retentionDaysOverride: null, tenantId: 'tenant-b');
        self::assertSame(1, $b['outbox']);
        self::assertSame(1, $b['inbox']);
        self::assertSame('tenant-b', $b['tenant_id']);

        // No deletes happened.
        self::assertSame(3, $this->outboxTotal());
        self::assertSame(2, $this->inboxTotal());
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function insertReplayKey(string $key, ?\DateTimeImmutable $expiresAt): void
    {
        $this->orm->getAdapter()->execute(
            sprintf(
                'INSERT INTO `%s` (replay_key, first_seen_at, expires_at) VALUES (:k, :seen, :exp)',
                MySqlWebhookReplayStore::TABLE,
            ),
            [
                'k'    => $key,
                'seen' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'exp'  => $expiresAt?->format('Y-m-d H:i:s'),
            ],
        );
    }

    private function replayKeyExists(string $key): bool
    {
        $row = $this->orm->getAdapter()->execute(
            sprintf('SELECT 1 FROM `%s` WHERE replay_key = :k', MySqlWebhookReplayStore::TABLE),
            ['k' => $key],
        )->fetchOne();
        return $row !== null;
    }

    private function insertOutbox(
        string $tag,
        OutboundStatus $status,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $leaseExpiresAt = null,
        ?string $tenantId = null,
    ): void {
        $this->orm->getAdapter()->execute(
            'INSERT INTO webhook_outbox
             (id, endpoint_definition_id, endpoint_key, provider_key, tenant_id, event_type, status,
              idempotency_key, payload_json, headers_json, signed_headers_json,
              next_attempt_at, last_attempt_at, delivered_at,
              attempt_count, max_attempts, initial_backoff_seconds, max_backoff_seconds,
              lease_owner, lease_expires_at,
              last_response_status, last_response_headers_json, last_response_body, last_error,
              source_ref, metadata_json, created_at, updated_at)
             VALUES
             (:id, :ep_def, :ep_key, :prov, :tenant, :evt, :status,
              :idem, :payload, NULL, NULL,
              :next_at, NULL, NULL,
              0, 5, 30, 600,
              NULL, :lease,
              NULL, NULL, NULL, NULL,
              NULL, NULL, :created, NULL)',
            [
                'id'      => random_bytes(16),
                'ep_def'  => str_pad('cleanup-ep', 16, "\0"),
                'ep_key'  => 'cleanup-test',
                'prov'    => 'cleanup-test',
                'tenant'  => $tenantId,
                'evt'     => 'cleanup.test.v1',
                'status'  => $status->value,
                'idem'    => $tag,
                'payload' => '{"tag":"' . $tag . '"}',
                'next_at' => $createdAt->format('Y-m-d H:i:s.u'),
                'lease'   => $leaseExpiresAt?->format('Y-m-d H:i:s.u'),
                'created' => $createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    private function insertInbox(string $dedupeKey, InboundStatus $status, \DateTimeImmutable $createdAt, ?string $tenantId = null): void
    {
        $this->orm->getAdapter()->execute(
            'INSERT INTO webhook_inbox
             (id, endpoint_definition_id, provider_key, endpoint_key, tenant_id, provider_event_id,
              dedupe_key, signature_status, status, content_type, http_method, request_uri,
              headers_json, raw_body, raw_body_sha256, parsed_event_type,
              first_received_at, last_received_at, processing_started_at, processed_at, failed_at,
              duplicate_count, last_error, metadata_json, created_at, updated_at)
             VALUES
             (:id, :ep_def, :prov, :ep_key, :tenant, :evt_id,
              :dedupe, :sig, :status, :ctype, :method, :uri,
              NULL, :body, :sha, :ptype,
              :recv_first, :recv_last, NULL, NULL, NULL,
              0, NULL, NULL, :created, NULL)',
            [
                'id'         => random_bytes(16),
                'ep_def'     => str_pad('cleanup-in', 16, "\0"),
                'prov'       => 'cleanup-test',
                'ep_key'     => 'cleanup-inbound',
                'tenant'     => $tenantId,
                'evt_id'     => 'evt-' . substr($dedupeKey, 0, 16),
                'dedupe'     => $dedupeKey,
                'sig'        => 'pending',
                'status'     => $status->value,
                'ctype'      => 'application/json',
                'method'     => 'POST',
                'uri'        => '/webhooks/cleanup',
                'body'       => '{}',
                'sha'        => hash('sha256', '{}'),
                'ptype'      => 'cleanup.test.v1',
                'recv_first' => $createdAt->format('Y-m-d H:i:s'),
                'recv_last'  => $createdAt->format('Y-m-d H:i:s'),
                'created'    => $createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    private function insertAttempt(string $tag, \DateTimeImmutable $createdAt): void
    {
        $this->orm->getAdapter()->execute(
            'INSERT INTO webhook_attempts
             (id, direction, inbox_id, outbox_id, event_type, attempt_number,
              status_before, status_after, worker_id, http_status, message, details_json, created_at)
             VALUES
             (:id, :dir, NULL, NULL, :evt, NULL,
              NULL, NULL, NULL, NULL, :msg, NULL, :created)',
            [
                'id'      => random_bytes(16),
                'dir'     => 'outbound',
                'evt'     => 'cleanup.test.v1',
                'msg'     => $tag,
                'created' => $createdAt->format('Y-m-d H:i:s.u'),
            ],
        );
    }

    private function outboxCountWhereId(string $idempotencyKey): int
    {
        return (int) $this->orm->getAdapter()->execute(
            'SELECT COUNT(*) FROM webhook_outbox WHERE idempotency_key = :k',
            ['k' => $idempotencyKey],
        )->fetchColumn();
    }

    private function inboxCountWhereDedupe(string $dedupeKey): int
    {
        return (int) $this->orm->getAdapter()->execute(
            'SELECT COUNT(*) FROM webhook_inbox WHERE dedupe_key = :k',
            ['k' => $dedupeKey],
        )->fetchColumn();
    }

    private function outboxTotal(): int
    {
        return (int) $this->orm->getAdapter()->execute('SELECT COUNT(*) FROM webhook_outbox')->fetchColumn();
    }

    private function inboxTotal(): int
    {
        return (int) $this->orm->getAdapter()->execute('SELECT COUNT(*) FROM webhook_inbox')->fetchColumn();
    }

    private function attemptCount(): int
    {
        return (int) $this->orm->getAdapter()->execute('SELECT COUNT(*) FROM webhook_attempts')->fetchColumn();
    }
}
