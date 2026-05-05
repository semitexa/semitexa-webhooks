<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;

/**
 * Integration test for the production MySQL-backed replay store.
 *
 * Uses real MySQL from the docker-compose test stack (DB_HOST=mysql,
 * DB_DATABASE=semitexa_test). Each test creates its own clean
 * webhook_replay_keys table via DROP+CREATE in setUp, so a leaked table
 * from a previous run never poisons the next run.
 *
 * The atomic-claim contract is tested end-to-end against the real
 * database — the unique-key constraint and INSERT IGNORE row-count
 * behavior are MySQL guarantees, not framework code, so verifying them
 * against the real engine is the only honest proof.
 */
final class MySqlWebhookReplayStoreTest extends TestCase
{
    private OrmManager $orm;
    private MySqlWebhookReplayStore $store;

    protected function setUp(): void
    {
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — integration test requires real MySQL');
        }

        try {
            $this->orm = ContainerFactory::get()->get(OrmManager::class);
            // Probe the connection.
            $this->orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }

        $this->resetTable();
        $this->store = new MySqlWebhookReplayStore($this->orm);
    }

    protected function tearDown(): void
    {
        if (!isset($this->orm)) {
            return;
        }
        try {
            $this->orm->getAdapter()->execute(
                sprintf('DROP TABLE IF EXISTS `%s`', MySqlWebhookReplayStore::TABLE),
            );
        } catch (\Throwable) {
            // best effort
        }
    }

    private function resetTable(): void
    {
        $adapter = $this->orm->getAdapter();
        $adapter->execute(sprintf('DROP TABLE IF EXISTS `%s`', MySqlWebhookReplayStore::TABLE));
        $adapter->execute(sprintf(
            'CREATE TABLE `%s` (
                replay_key VARCHAR(191) NOT NULL,
                first_seen_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                PRIMARY KEY (replay_key),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            MySqlWebhookReplayStore::TABLE,
        ));
    }

    // ------------------------------------------------------------------
    //  Atomic claim — primary contract
    // ------------------------------------------------------------------

    #[Test]
    public function first_markIfFirstSeen_returns_true(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('replay-test-first'));
    }

    #[Test]
    public function second_markIfFirstSeen_for_same_key_returns_false(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('replay-test-replay'));
        self::assertFalse($this->store->markIfFirstSeen('replay-test-replay'));
    }

    #[Test]
    public function different_keys_are_independent(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('replay-test-alpha'));
        self::assertTrue($this->store->markIfFirstSeen('replay-test-beta'));
    }

    #[Test]
    public function seen_returns_false_before_first_mark(): void
    {
        self::assertFalse($this->store->seen('replay-test-not-yet'));
    }

    #[Test]
    public function seen_returns_true_after_first_mark(): void
    {
        $this->store->markIfFirstSeen('replay-test-seen-after');
        self::assertTrue($this->store->seen('replay-test-seen-after'));
    }

    // ------------------------------------------------------------------
    //  TTL / expiry — Option B (blocked until cleanup)
    // ------------------------------------------------------------------

    #[Test]
    public function ttl_writes_expires_at_correctly(): void
    {
        $this->store->markIfFirstSeen('replay-test-ttl', 60);
        $row = $this->orm->getAdapter()->execute(
            sprintf('SELECT replay_key, first_seen_at, expires_at FROM `%s` WHERE replay_key = :k', MySqlWebhookReplayStore::TABLE),
            ['k' => 'replay-test-ttl'],
        )->fetchOne();
        self::assertNotNull($row);
        self::assertSame('replay-test-ttl', $row['replay_key']);
        self::assertNotNull($row['expires_at']);
        $expiresAt = new \DateTimeImmutable($row['expires_at']);
        $firstSeenAt = new \DateTimeImmutable($row['first_seen_at']);
        self::assertGreaterThanOrEqual(59, $expiresAt->getTimestamp() - $firstSeenAt->getTimestamp());
        self::assertLessThanOrEqual(61, $expiresAt->getTimestamp() - $firstSeenAt->getTimestamp());
    }

    #[Test]
    public function null_ttl_writes_null_expires_at(): void
    {
        $this->store->markIfFirstSeen('replay-test-no-ttl', null);
        $row = $this->orm->getAdapter()->execute(
            sprintf('SELECT expires_at FROM `%s` WHERE replay_key = :k', MySqlWebhookReplayStore::TABLE),
            ['k' => 'replay-test-no-ttl'],
        )->fetchOne();
        self::assertNotNull($row);
        self::assertNull($row['expires_at']);
    }

    #[Test]
    public function expired_key_remains_blocked_until_cleanup(): void
    {
        // Expired rows continue to block markIfFirstSeen until
        // cleanupExpired() removes them. This is the conservative,
        // race-free policy — delete-then-insert would reintroduce the
        // check-then-act race the atomic claim is meant to prevent.
        $this->store->markIfFirstSeen('replay-test-expired', 1);
        sleep(2);
        self::assertFalse(
            $this->store->markIfFirstSeen('replay-test-expired', 1),
            'expired row must still block — cleanup runs operationally',
        );
    }

    #[Test]
    public function cleanupExpired_removes_expired_rows_and_returns_count(): void
    {
        $this->store->markIfFirstSeen('replay-test-clean-1', 1);
        $this->store->markIfFirstSeen('replay-test-clean-2', 1);
        $this->store->markIfFirstSeen('replay-test-keep-no-ttl', null);
        $this->store->markIfFirstSeen('replay-test-keep-future', 3600);
        sleep(2);

        $deleted = $this->store->cleanupExpired();

        self::assertSame(2, $deleted, 'two ttl=1s rows expired');
        self::assertFalse($this->store->seen('replay-test-clean-1'));
        self::assertFalse($this->store->seen('replay-test-clean-2'));
        self::assertTrue($this->store->seen('replay-test-keep-no-ttl'), 'NULL expires_at row must NOT be deleted');
        self::assertTrue($this->store->seen('replay-test-keep-future'), 'future expires_at row must NOT be deleted');
    }

    #[Test]
    public function key_freed_by_cleanupExpired_can_be_reclaimed(): void
    {
        $this->store->markIfFirstSeen('replay-test-recycle', 1);
        sleep(2);
        $this->store->cleanupExpired();
        self::assertTrue(
            $this->store->markIfFirstSeen('replay-test-recycle', 60),
            'after cleanup, the same key is once again first-seen',
        );
    }

    // ------------------------------------------------------------------
    //  Cross-instance / cross-worker simulation
    // ------------------------------------------------------------------

    #[Test]
    public function two_separate_store_instances_share_the_database(): void
    {
        // Simulates two PHP workers, each with their own MySqlWebhookReplayStore
        // wrapping the same OrmManager (same DB). The atomicity guarantee
        // comes from the DB primary-key constraint, not from sharing state in
        // PHP — pin that.
        $a = new MySqlWebhookReplayStore($this->orm);
        $b = new MySqlWebhookReplayStore($this->orm);
        self::assertTrue($a->markIfFirstSeen('replay-test-cross-A'));
        self::assertFalse($b->markIfFirstSeen('replay-test-cross-A'));
    }

    #[Test]
    public function duplicate_key_is_handled_as_false_not_as_fatal(): void
    {
        // Pinning that INSERT IGNORE behavior reaches PHP as a clean false,
        // not as a thrown PDO exception. This is what makes the contract
        // safe for callers that don't wrap markIfFirstSeen in try/catch.
        $this->store->markIfFirstSeen('replay-test-no-throw');
        $threw = false;
        try {
            $second = $this->store->markIfFirstSeen('replay-test-no-throw');
            self::assertFalse($second);
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertFalse($threw, 'duplicate must not throw');
    }

    // ------------------------------------------------------------------
    //  Compatibility with seen / markSeen / clear
    // ------------------------------------------------------------------

    #[Test]
    public function legacy_markSeen_remains_callable_for_compat(): void
    {
        $this->store->markSeen('replay-test-legacy-mark');
        self::assertTrue($this->store->seen('replay-test-legacy-mark'));
        self::assertFalse(
            $this->store->markIfFirstSeen('replay-test-legacy-mark'),
            'after legacy markSeen, markIfFirstSeen must report not-first',
        );
    }

    #[Test]
    public function clear_wipes_every_row(): void
    {
        $this->store->markIfFirstSeen('replay-test-clear-1');
        $this->store->markIfFirstSeen('replay-test-clear-2');
        $this->store->clear();
        self::assertFalse($this->store->seen('replay-test-clear-1'));
        self::assertFalse($this->store->seen('replay-test-clear-2'));
        self::assertTrue($this->store->markIfFirstSeen('replay-test-clear-1'), 'after clear, key reclaimable');
    }
}
