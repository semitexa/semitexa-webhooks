<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Auth\DatabaseWebhookReplayStore;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;

/**
 * The database replay store is the default now, and it spoke only MySQL:
 * INSERT IGNORE and DELETE ... LIMIT are syntax errors on SQLite, where the
 * inbox itself works. The claim and the batched cleanup are standard SQL now,
 * with MySQL's form kept for MySQL (tk-webhooks-replay-check-off-with-the-default-store).
 */
final class ReplayStoreOnSqliteTest extends TestCase
{
    #[Test]
    public function a_key_is_claimed_once_and_expired_keys_go_in_batches_on_sqlite(): void
    {
        $store = new MySqlWebhookReplayStore(self::sqlite());

        self::assertTrue($store->markIfFirstSeen('kept'));
        self::assertFalse($store->markIfFirstSeen('kept'), 'the second claim of a key loses');
        $store->markSeen('seen-twice');
        $store->markSeen('seen-twice');
        self::assertTrue($store->seen('seen-twice'));

        foreach (['gone-1', 'gone-2', 'gone-3'] as $key) {
            self::assertTrue($store->markIfFirstSeen($key, 1));
        }
        $later = new \DateTimeImmutable('+1 hour');
        self::assertSame(3, $store->countExpired($later));
        self::assertSame(2, $store->cleanupExpired($later, limit: 2));
        self::assertSame(1, $store->cleanupExpired($later));
        self::assertTrue($store->seen('kept'), 'a key with no expiry stays');
    }

    #[Test]
    public function the_default_binding_claims_through_the_database_store_it_is_given(): void
    {
        $default = new DatabaseWebhookReplayStore();
        (new \ReflectionProperty(DatabaseWebhookReplayStore::class, 'orm'))->setValue($default, self::sqlite());

        self::assertTrue($default->markIfFirstSeen('k', 600));
        self::assertFalse($default->markIfFirstSeen('k', 600));
        self::assertSame(1, $default->cleanupExpired(new \DateTimeImmutable('+1 hour'), limit: 5));
    }

    private static function sqlite(): OrmManager
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(sprintf(
            'CREATE TABLE %s (replay_key VARCHAR(191) NOT NULL PRIMARY KEY, first_seen_at DATETIME NOT NULL, expires_at DATETIME NULL)',
            MySqlWebhookReplayStore::TABLE,
        ));

        return $orm;
    }
}
