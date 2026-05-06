<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * Redis-backed production webhook replay store.
 *
 * Atomicity contract:
 *   markIfFirstSeen() uses Redis SET key value NX EX <ttl>. The Redis server
 *   guarantees this is atomic across all connected clients, so two workers
 *   (or two coroutines, or two processes) competing on the same key get
 *   exactly one winner. The loser's SET returns null; the winner's SET
 *   returns OK.
 *
 * TTL contract:
 *   When $ttlSeconds is provided, Redis sets EX automatically. The key
 *   expires server-side after the replay window, mirroring the
 *   AsWebhookReceiver::$replayWindowSeconds intent. When $ttlSeconds is
 *   null, this implementation defaults to a very long expiry (1 year)
 *   rather than no expiry — Redis SET NX with no EX would persist forever
 *   and accumulate keys unbounded over the worker lifetime, which is a
 *   memory leak waiting to happen. Callers that genuinely want forever
 *   should use a DB-backed store, not Redis.
 *
 * Key namespacing:
 *   The store does NOT prepend a prefix — caller keys flow through as-is.
 *   Production deployments should pass already-namespaced keys
 *   (e.g. "webhook:demo:signed:evt-123") to avoid collisions with other
 *   Redis state in the same database.
 *
 * Service binding:
 *   Intentionally NOT marked with #[SatisfiesServiceContract]. Production
 *   deployments wire this explicitly in the container bootstrap (or via
 *   their own factory/configuration mechanism) so the choice of replay
 *   store is an explicit deployment decision, not framework magic. The
 *   default InMemoryWebhookReplayStore wins in tests and demos.
 *
 * Connection management:
 *   Uses RedisConnectionPool::withConnection() — a borrowed Predis client
 *   is auto-returned to the pool after the operation. Connection failures
 *   propagate as exceptions and the pool transparently swaps in a fresh
 *   client.
 */
final class RedisWebhookReplayStore implements WebhookReplayStoreInterface
{
    /**
     * Default expiry for null-TTL calls. 1 year — long enough to cover any
     * legitimate replay window, short enough to prevent unbounded growth
     * over a worker's lifetime.
     */
    private const DEFAULT_TTL_SECONDS = 31_536_000;

    public function __construct(private readonly RedisConnectionPool $pool) {}

    public function seen(string $key): bool
    {
        /** @var int $exists */
        $exists = $this->pool->withConnection(static fn ($redis) => $redis->exists($key));
        return $exists > 0;
    }

    public function markSeen(string $key): void
    {
        $this->pool->withConnection(static function ($redis) use ($key): void {
            $redis->set($key, '1', 'EX', self::DEFAULT_TTL_SECONDS);
        });
    }

    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;
        if ($ttl < 1) {
            // Redis EX requires >= 1 second; clamp absurd inputs to a tiny window
            // rather than crashing — the caller's intent is "very short replay
            // window" and a 1-second floor is the most defensible interpretation.
            $ttl = 1;
        }

        $reply = $this->pool->withConnection(
            static fn ($redis) => $redis->set($key, '1', 'EX', $ttl, 'NX'),
        );

        // Predis returns the Redis status reply 'OK' (or a Status object whose
        // string cast is 'OK') on success; null when NX prevented the set.
        return $reply !== null && (string) $reply === 'OK';
    }

    /**
     * Test/admin-scoped clear. NOT for production runtime — calls FLUSHDB
     * which would wipe every key in the current Redis database, including
     * sessions, caches, and any other Redis-backed state. Production
     * deployments must implement a prefix-scoped clear via SCAN if they
     * need one.
     */
    public function clear(): void
    {
        $this->pool->withConnection(static function ($redis): void {
            $redis->flushdb();
        });
    }
}
