<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Auth\Contract\OwnedClaimUnavailableException;
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

    /** A claim with its owner: its own again, nobody else's, and never an ownerless key's (review, semitexa-webhooks#43). */
    #[Test]
    public function a_claim_names_its_owner_on_sqlite(): void
    {
        $orm = self::sqlite();
        $default = new DatabaseWebhookReplayStore();
        (new \ReflectionProperty(DatabaseWebhookReplayStore::class, 'orm'))->setValue($default, $orm);

        self::assertTrue($default->claimFor('bytes', 'delivery-a', 600), 'the first claim wins');
        self::assertTrue((new MySqlWebhookReplayStore($orm))->claimFor('bytes', 'delivery-a', 600), 'its owner claims it again');
        self::assertFalse($default->claimFor('bytes', 'delivery-b', 600), 'another owner does not');

        self::assertTrue($default->markIfFirstSeen('ownerless', 600));
        self::assertFalse($default->claimFor('ownerless', 'delivery-a', 600), 'a key claimed with no owner is nobody\'s');
    }

    #[Test]
    public function a_table_from_before_the_owner_column_says_so_and_writes_nothing(): void
    {
        $store = new MySqlWebhookReplayStore(self::sqlite(owner: false));

        try {
            $store->claimFor('bytes', 'delivery-a', 600);
            self::fail('a claim with no owner column to hold its owner passed');
        } catch (OwnedClaimUnavailableException $e) {
            self::assertStringContainsString('orm:sync', $e->getMessage());
        }
        self::assertTrue($store->markIfFirstSeen('bytes', 600), 'nothing was claimed by the failed call');
    }

    private static function sqlite(bool $owner = true): OrmManager
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $orm->getAdapter()->execute(sprintf(
            'CREATE TABLE %s (replay_key VARCHAR(191) NOT NULL PRIMARY KEY, first_seen_at DATETIME NOT NULL, expires_at DATETIME NULL%s)',
            MySqlWebhookReplayStore::TABLE,
            $owner ? ', owner VARCHAR(64) NULL' : '',
        ));

        return $orm;
    }
}
