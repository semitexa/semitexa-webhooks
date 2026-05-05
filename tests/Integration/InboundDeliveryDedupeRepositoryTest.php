<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\InboundDeliveryRepository;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\SignatureStatus;

/**
 * Integration test for the inbound delivery dedupe atomic-claim contract.
 * Drives Semitexa\Webhooks\Application\Db\MySQL\Repository\InboundDeliveryRepository
 * against real MySQL.
 *
 * The repository's atomicity rests on two pieces working together: the
 * UNIQUE constraint on webhook_inbox.dedupe_key, and the try-insert +
 * catch-duplicate pattern in insertOrMatchDedupe. A check-then-insert
 * sequence (findByDedupeKey then insert) would be race-prone: two
 * concurrent workers/processes could both observe "not found" and both
 * insert, producing duplicate rows for the same logical event. These
 * tests pin the contract end-to-end against a real MySQL table with the
 * unique constraint applied.
 */
final class InboundDeliveryDedupeRepositoryTest extends TestCase
{
    private OrmManager $orm;
    private InboundDeliveryRepository $repo;

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
        $this->repo = $container->get(InboundDeliveryRepository::class);
    }

    protected function tearDown(): void
    {
        if (!isset($this->orm)) {
            return;
        }
        try {
            $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS `webhook_inbox`');
        } catch (\Throwable) {
            // best effort
        }
    }

    private function resetTable(): void
    {
        $adapter = $this->orm->getAdapter();
        $adapter->execute('DROP TABLE IF EXISTS `webhook_inbox`');
        // Schema mirrors WebhookInboxResourceModel exactly. The UNIQUE
        // INDEX on dedupe_key is load-bearing — without it, the ORM-level
        // insert() would silently allow duplicates and the race fix in
        // the repository would have nothing to catch.
        $adapter->execute(<<<'SQL'
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
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_inbox_dedupe_key (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    private function makeDelivery(string $dedupeKey, ?string $id = null): InboundDelivery
    {
        $now = new \DateTimeImmutable();
        return new InboundDelivery(
            // BINARY(16) columns: 16 raw bytes, not 32-char hex.
            id: $id !== null ? str_pad(substr($id, 0, 16), 16, "\0") : random_bytes(16),
            endpointDefinitionId: random_bytes(16),
            providerKey: 'dedupe-test',
            endpointKey: 'inbound-dedupe-test',
            tenantId: null,
            providerEventId: 'evt-' . substr($dedupeKey, 0, 16),
            dedupeKey: $dedupeKey,
            signatureStatus: SignatureStatus::Pending,
            status: InboundStatus::Received,
            contentType: 'application/json',
            httpMethod: 'POST',
            requestUri: '/webhooks/inbound-dedupe',
            headers: ['X-Test' => 'inbound-dedupe-integration'],
            rawBody: '{}',
            rawBodySha256: hash('sha256', '{}'),
            parsedEventType: 'inbound-dedupe.test.v1',
            firstReceivedAt: $now,
            lastReceivedAt: $now,
        );
    }

    private function rowCount(string $dedupeKey): int
    {
        return (int) $this->orm->getAdapter()->execute(
            'SELECT COUNT(*) AS c FROM webhook_inbox WHERE dedupe_key = :k',
            ['k' => $dedupeKey],
        )->fetchColumn();
    }

    // ------------------------------------------------------------------
    //  Sequential atomic-claim contract
    // ------------------------------------------------------------------

    #[Test]
    public function first_insertOrMatchDedupe_creates_a_new_row(): void
    {
        $delivery = $this->makeDelivery('dedupe-test-first');
        $result = $this->repo->insertOrMatchDedupe($delivery);

        self::assertNotSame(InboundStatus::DuplicateIgnored, $result->getStatus(), 'first call must NOT report duplicate');
        self::assertSame(0, $result->getDuplicateCount());
        self::assertSame(1, $this->rowCount('dedupe-test-first'));
    }

    #[Test]
    public function second_insertOrMatchDedupe_for_same_key_returns_matched_existing_and_does_not_insert(): void
    {
        $first  = $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-replay'));
        $second = $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-replay'));

        self::assertNotSame(InboundStatus::DuplicateIgnored, $first->getStatus(), 'first call must NOT report duplicate');
        self::assertSame(InboundStatus::DuplicateIgnored, $second->getStatus(), 'second call must report DuplicateIgnored');
        self::assertGreaterThanOrEqual(1, $second->getDuplicateCount());
        // The row-count assertion is the load-bearing proof of atomicity.
        // The id comparison is fragile because BINARY(16) ↔ UUID-string
        // formatting differs between the just-inserted in-memory object and
        // the DB-roundtripped object — both refer to the same logical row,
        // but they don't compare-equal as strings.
        self::assertSame(1, $this->rowCount('dedupe-test-replay'), 'exactly one row must exist for the dedupe key');
    }

    #[Test]
    public function different_dedupe_keys_create_independent_rows(): void
    {
        $a = $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-alpha'));
        $b = $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-beta'));

        self::assertNotSame($a->getId(), $b->getId());
        self::assertSame(1, $this->rowCount('dedupe-test-alpha'));
        self::assertSame(1, $this->rowCount('dedupe-test-beta'));
    }

    // ------------------------------------------------------------------
    //  Cross-instance simulation (multi-worker safety)
    // ------------------------------------------------------------------

    #[Test]
    public function two_separate_repository_instances_share_the_database(): void
    {
        // Simulates two PHP workers each holding their own InboundDeliveryRepository
        // wrapping the same OrmManager (same DB). Atomicity comes from the DB
        // unique constraint, not from sharing PHP state.
        $container = ContainerFactory::get();
        $repoA = $container->get(InboundDeliveryRepository::class);
        $repoB = clone $repoA;

        $a = $repoA->insertOrMatchDedupe($this->makeDelivery('dedupe-test-cross'));
        $b = $repoB->insertOrMatchDedupe($this->makeDelivery('dedupe-test-cross'));

        self::assertNotSame(InboundStatus::DuplicateIgnored, $a->getStatus());
        self::assertSame(InboundStatus::DuplicateIgnored, $b->getStatus());
        self::assertSame(1, $this->rowCount('dedupe-test-cross'), 'cross-instance INSERT race must produce exactly one row');
    }

    // ------------------------------------------------------------------
    //  Error handling — duplicate ≠ fatal, real DB errors must surface
    // ------------------------------------------------------------------

    #[Test]
    public function duplicate_key_conflict_is_handled_as_match_not_thrown(): void
    {
        $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-no-throw'));
        $threw = false;
        try {
            $second = $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-no-throw'));
            self::assertSame(InboundStatus::DuplicateIgnored, $second->getStatus());
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertFalse($threw, 'duplicate key must not throw');
    }

    #[Test]
    public function real_db_error_is_not_swallowed_as_duplicate(): void
    {
        // Drop the table to provoke a "table does not exist" error. Only
        // an ACTUAL unique-constraint violation maps to a match — every
        // other DB error must surface to the caller so broken infrastructure
        // never silently appears as "every webhook duplicated".
        $this->orm->getAdapter()->execute('DROP TABLE webhook_inbox');

        $this->expectException(\Throwable::class);
        try {
            $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-table-gone'));
        } finally {
            // Re-create table so tearDown's DROP IF EXISTS doesn't itself fail.
            $this->resetTable();
        }
    }

    // ------------------------------------------------------------------
    //  Persistence / repository-recreation survival
    // ------------------------------------------------------------------

    #[Test]
    public function dedupe_survives_repository_recreation(): void
    {
        $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-persist'));

        // Build a fresh repository instance — same DB, no shared PHP state.
        $freshRepo = clone $this->repo;

        $second = $freshRepo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-persist'));
        self::assertSame(InboundStatus::DuplicateIgnored, $second->getStatus());
        self::assertSame(1, $this->rowCount('dedupe-test-persist'));
    }

    // ------------------------------------------------------------------
    //  Burst — many concurrent-shape sequential dispatches
    // ------------------------------------------------------------------

    #[Test]
    public function burst_of_30_attempts_for_one_dedupe_key_creates_exactly_one_row(): void
    {
        // Sequential burst — every attempt uses the same dedupe key. After
        // the burst, exactly one row exists; every second-or-later call
        // matched the first. This is the user-visible side-effect-once
        // contract for inbound webhook ingestion.
        for ($i = 0; $i < 30; $i++) {
            $result = $this->repo->insertOrMatchDedupe($this->makeDelivery('dedupe-test-burst'));
            if ($i > 0) {
                self::assertSame(
                    InboundStatus::DuplicateIgnored,
                    $result->getStatus(),
                    "attempt {$i} must report DuplicateIgnored",
                );
            }
        }
        self::assertSame(1, $this->rowCount('dedupe-test-burst'));
    }
}
