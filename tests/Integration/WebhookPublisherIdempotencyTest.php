<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\OutboundDeliveryRepository;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;

/**
 * Integration tests for outbound publisher idempotency at the persistence
 * boundary. Drives OutboundDeliveryRepository::insertOrMatchIdempotency
 * against real MySQL.
 *
 * Idempotency policy:
 *   - idempotency_key is OPTIONAL. NULL keys always create a new row
 *     (MySQL UNIQUE indexes allow multiple NULLs by design).
 *   - Non-NULL keys are unique per (endpoint_definition_id, idempotency_key).
 *     Two publishers with the same key targeting the same endpoint match
 *     the existing row; same key on a different endpoint is a separate
 *     delivery.
 *   - Caller can detect "matched existing" by comparing the returned
 *     delivery's id against the input delivery's id (id changes if the
 *     existing row was returned).
 */
final class WebhookPublisherIdempotencyTest extends TestCase
{
    private OrmManager $orm;
    private OutboundDeliveryRepository $repo;

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

        $this->resetTable();
        $this->repo = $container->get(OutboundDeliveryRepository::class);
    }

    protected function tearDown(): void
    {
        if (!isset($this->orm)) {
            return;
        }
        try {
            $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS `webhook_outbox`');
        } catch (\Throwable) {
            // best effort
        }
    }

    private function resetTable(): void
    {
        $adapter = $this->orm->getAdapter();
        $adapter->execute('DROP TABLE IF EXISTS `webhook_outbox`');
        $adapter->execute(<<<'SQL'
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
    }

    private function makeDelivery(?string $idempotencyKey, ?string $endpointDefinitionId = null): OutboundDelivery
    {
        $now = new \DateTimeImmutable();
        return new OutboundDelivery(
            id: random_bytes(16),
            endpointDefinitionId: $endpointDefinitionId ?? str_pad('default', 16, "\0"),
            endpointKey: 'idempotency-test',
            providerKey: 'idempotency-test',
            tenantId: null,
            eventType: 'idempotency.test.v1',
            status: OutboundStatus::Pending,
            idempotencyKey: $idempotencyKey,
            payloadJson: '{"event":"x"}',
            headersJson: null,
            signedHeadersJson: null,
            nextAttemptAt: $now,
            lastAttemptAt: null,
            deliveredAt: null,
            attemptCount: 0,
            maxAttempts: 3,
            initialBackoffSeconds: 30,
            maxBackoffSeconds: 600,
            leaseOwner: null,
            leaseExpiresAt: null,
            lastResponseStatus: null,
            lastResponseHeadersJson: null,
            lastResponseBody: null,
            lastError: null,
            sourceRef: null,
            metadata: null,
            createdAt: $now,
        );
    }

    private function rowCount(?string $idempotencyKey, ?string $endpointDefinitionId = null): int
    {
        $endpointDefinitionId ??= str_pad('default', 16, "\0");
        if ($idempotencyKey === null) {
            return (int) $this->orm->getAdapter()->execute(
                'SELECT COUNT(*) FROM webhook_outbox WHERE endpoint_definition_id = :ep AND idempotency_key IS NULL',
                ['ep' => $endpointDefinitionId],
            )->fetchColumn();
        }
        return (int) $this->orm->getAdapter()->execute(
            'SELECT COUNT(*) FROM webhook_outbox WHERE endpoint_definition_id = :ep AND idempotency_key = :k',
            ['ep' => $endpointDefinitionId, 'k' => $idempotencyKey],
        )->fetchColumn();
    }

    // ------------------------------------------------------------------
    //  Optional-idempotency policy
    // ------------------------------------------------------------------

    #[Test]
    public function publish_without_idempotency_key_creates_a_delivery(): void
    {
        $result = $this->repo->insertOrMatchIdempotency($this->makeDelivery(null));
        self::assertSame(OutboundStatus::Pending, $result->getStatus());
        self::assertSame(1, $this->rowCount(null));
    }

    #[Test]
    public function two_publishes_without_idempotency_key_create_two_deliveries(): void
    {
        $this->repo->insertOrMatchIdempotency($this->makeDelivery(null));
        $this->repo->insertOrMatchIdempotency($this->makeDelivery(null));
        self::assertSame(2, $this->rowCount(null), 'optional idempotency policy: NULL keys do not dedupe');
    }

    // ------------------------------------------------------------------
    //  Atomic match for non-null keys
    // ------------------------------------------------------------------

    #[Test]
    public function first_publish_with_idempotency_key_creates_a_delivery(): void
    {
        $a = $this->makeDelivery('idem-first');
        $result = $this->repo->insertOrMatchIdempotency($a);
        self::assertSame($a->getId(), $result->getId(), 'first call returns the freshly-inserted delivery');
        self::assertSame(1, $this->rowCount('idem-first'));
    }

    #[Test]
    public function second_publish_with_same_idempotency_key_returns_existing_delivery(): void
    {
        $first  = $this->makeDelivery('idem-replay');
        $second = $this->makeDelivery('idem-replay'); // different id, same key

        $a = $this->repo->insertOrMatchIdempotency($first);
        $b = $this->repo->insertOrMatchIdempotency($second);

        self::assertSame(1, $this->rowCount('idem-replay'), 'duplicate publish must not create a second row');
        // The matched delivery is the FIRST one — same id as $first, NOT $second.
        // Caller can detect "matched existing" by comparing returned id != input id.
        self::assertNotSame($second->getId(), $b->getId(), 'matched delivery is the existing row, not the just-built duplicate');
    }

    #[Test]
    public function different_idempotency_keys_create_independent_deliveries(): void
    {
        $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-alpha'));
        $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-beta'));
        self::assertSame(1, $this->rowCount('idem-alpha'));
        self::assertSame(1, $this->rowCount('idem-beta'));
    }

    #[Test]
    public function same_key_on_different_endpoints_creates_two_deliveries(): void
    {
        $epA = str_pad('endpoint-A', 16, "\0");
        $epB = str_pad('endpoint-B', 16, "\0");

        $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-shared', $epA));
        $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-shared', $epB));

        self::assertSame(1, $this->rowCount('idem-shared', $epA));
        self::assertSame(1, $this->rowCount('idem-shared', $epB));
    }

    // ------------------------------------------------------------------
    //  Cross-instance simulation (multi-publisher safety)
    // ------------------------------------------------------------------

    #[Test]
    public function two_separate_repository_instances_cannot_both_create_for_same_idempotency_key(): void
    {
        $repoA = ContainerFactory::get()->get(OutboundDeliveryRepository::class);
        $repoB = clone $repoA;

        $repoA->insertOrMatchIdempotency($this->makeDelivery('idem-cross-instance'));
        $repoB->insertOrMatchIdempotency($this->makeDelivery('idem-cross-instance'));

        self::assertSame(1, $this->rowCount('idem-cross-instance'), 'cross-instance race must produce exactly one row');
    }

    // ------------------------------------------------------------------
    //  Error handling
    // ------------------------------------------------------------------

    #[Test]
    public function duplicate_idempotency_key_is_handled_as_match_not_thrown(): void
    {
        $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-no-throw'));
        $threw = false;
        try {
            $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-no-throw'));
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertFalse($threw, 'duplicate idempotency key must not throw');
    }

    #[Test]
    public function real_db_error_is_not_swallowed_as_duplicate(): void
    {
        $this->orm->getAdapter()->execute('DROP TABLE webhook_outbox');

        $this->expectException(\Throwable::class);
        try {
            $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-table-gone'));
        } finally {
            $this->resetTable();
        }
    }

    // ------------------------------------------------------------------
    //  Burst / sustained
    // ------------------------------------------------------------------

    #[Test]
    public function burst_of_25_publishes_for_one_idempotency_key_creates_exactly_one_row(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->repo->insertOrMatchIdempotency($this->makeDelivery('idem-burst'));
        }
        self::assertSame(1, $this->rowCount('idem-burst'));
    }
}
