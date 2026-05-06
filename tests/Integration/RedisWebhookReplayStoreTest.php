<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Webhooks\Auth\RedisWebhookReplayStore;

/**
 * Cycle-16 integration test for the production Redis-backed replay store.
 *
 * Uses real Redis from the docker-compose test stack (REDIS_HOST=redis).
 * Each test scopes its keys with a unique prefix so parallel runs don't
 * collide. tearDown cleans up the prefix's keys via SCAN — never
 * FLUSHDB, which would wipe sessions and other Redis state.
 */
final class RedisWebhookReplayStoreTest extends TestCase
{
    private RedisConnectionPool $pool;
    private string $keyPrefix;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('REDIS_HOST not configured — integration test requires real Redis');
        }
        $this->pool = new RedisConnectionPool(2, [
            'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
            'host'   => $host,
            'port'   => (int) (getenv('REDIS_PORT') ?: '6379'),
            'password' => (string) (getenv('REDIS_PASSWORD') ?: ''),
        ]);
        // Probe Redis is actually reachable. If not, mark skipped instead of failing.
        try {
            $this->pool->withConnection(static fn ($redis) => $redis->ping());
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
        $this->keyPrefix = 'cycle16:test:' . bin2hex(random_bytes(8)) . ':';
    }

    protected function tearDown(): void
    {
        if (!isset($this->pool)) {
            return;
        }
        $this->pool->withConnection(function ($redis): void {
            $cursor = '0';
            do {
                $reply = $redis->scan($cursor, ['MATCH' => $this->keyPrefix . '*', 'COUNT' => 100]);
                [$cursor, $keys] = is_array($reply) ? $reply : [$cursor, []];
                if (is_array($keys) && $keys !== []) {
                    $redis->del($keys);
                }
            } while ($cursor !== '0' && $cursor !== 0);
        });
    }

    private function k(string $suffix): string
    {
        return $this->keyPrefix . $suffix;
    }

    #[Test]
    public function first_markIfFirstSeen_returns_true(): void
    {
        $store = new RedisWebhookReplayStore($this->pool);
        self::assertTrue($store->markIfFirstSeen($this->k('first-call')));
    }

    #[Test]
    public function second_markIfFirstSeen_for_same_key_returns_false(): void
    {
        $store = new RedisWebhookReplayStore($this->pool);
        $key = $this->k('replay-twice');
        self::assertTrue($store->markIfFirstSeen($key));
        self::assertFalse($store->markIfFirstSeen($key));
    }

    #[Test]
    public function different_keys_are_independent(): void
    {
        $store = new RedisWebhookReplayStore($this->pool);
        self::assertTrue($store->markIfFirstSeen($this->k('alpha')));
        self::assertTrue($store->markIfFirstSeen($this->k('beta')));
    }

    #[Test]
    public function seen_returns_true_after_first_mark(): void
    {
        $store = new RedisWebhookReplayStore($this->pool);
        $key = $this->k('seen-after-mark');
        self::assertFalse($store->seen($key));
        $store->markIfFirstSeen($key);
        self::assertTrue($store->seen($key));
    }

    #[Test]
    public function ttl_is_applied_and_key_expires(): void
    {
        $store = new RedisWebhookReplayStore($this->pool);
        $key = $this->k('ttl-1s');
        self::assertTrue($store->markIfFirstSeen($key, 1), 'first mark with 1s TTL');
        self::assertFalse($store->markIfFirstSeen($key, 1), 'second mark within window');
        sleep(2);
        self::assertTrue($store->markIfFirstSeen($key, 1), 'third mark after TTL expiry should win');
    }

    #[Test]
    public function two_separate_store_instances_share_the_same_redis_backend(): void
    {
        // Simulates two workers / processes pointing at the same Redis DB —
        // the cross-process atomicity guarantee an in-memory store cannot make.
        $a = new RedisWebhookReplayStore($this->pool);
        $b = new RedisWebhookReplayStore($this->pool);
        $key = $this->k('shared-backend');
        self::assertTrue($a->markIfFirstSeen($key));
        self::assertFalse($b->markIfFirstSeen($key));
    }

    #[Test]
    public function two_pools_against_same_redis_db_still_only_one_winner(): void
    {
        // Two RedisConnectionPool instances simulate two distinct workers
        // each with their own connection pool. Same Redis server, same DB.
        // Atomicity guarantee comes from the Redis server, not from sharing
        // a connection. Pin that contract.
        $secondPool = new RedisConnectionPool(2, [
            'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
            'host'   => getenv('REDIS_HOST'),
            'port'   => (int) (getenv('REDIS_PORT') ?: '6379'),
            'password' => (string) (getenv('REDIS_PASSWORD') ?: ''),
        ]);
        $a = new RedisWebhookReplayStore($this->pool);
        $b = new RedisWebhookReplayStore($secondPool);
        $key = $this->k('two-pools');
        self::assertTrue($a->markIfFirstSeen($key));
        self::assertFalse($b->markIfFirstSeen($key));
    }
}
