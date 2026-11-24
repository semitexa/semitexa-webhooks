<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Webhooks\Auth\Contract\OwnedClaimStoreInterface;
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
 *   expires server-side after the caller's replay window (the inbox passes
 *   InboundWebhookReceiver::REPLAY_WINDOW_SECONDS). When $ttlSeconds is
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
 *   default is DatabaseWebhookReplayStore; a project binds this one with a
 *   class of its own that builds it, which wins over the package's default.
 *
 * Connection management:
 *   Uses RedisConnectionPool::withConnection() — a borrowed Predis client
 *   is auto-returned to the pool after the operation. Connection failures
 *   propagate as exceptions and the pool transparently swaps in a fresh
 *   client.
 */
final class RedisWebhookReplayStore implements WebhookReplayStoreInterface, OwnedClaimStoreInterface
{
    /** What an owned claim's value starts with, so the ownerless marker '1' is never an owner. */
    private const OWNED_VALUE_PREFIX = 'owner:';

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
     * The claim with its owner as the key's value: SET NX EX writes both at
     * once, and when another call was first, the value says whose the key is.
     * A key set by markIfFirstSeen() holds '1' and is nobody's; an owned
     * value carries a prefix, so an owner named '1' does not take that
     * marker for its own (review, semitexa-webhooks#43).
     */
    public function claimFor(string $key, string $owner, ?int $ttlSeconds = null): bool
    {
        $ttl = max(1, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS);
        $owner = self::OWNED_VALUE_PREFIX . $owner;

        return $this->pool->withConnection(static function ($redis) use ($key, $owner, $ttl): bool {
            $reply = $redis->set($key, $owner, 'EX', $ttl, 'NX');
            if ($reply !== null && (string) $reply === 'OK') {
                return true;
            }

            // Expired between the two commands: null, not this owner's, so
            // false. It cannot meet a verified request while the clocks
            // agree: the receiver's TTL is the window in which the bytes
            // verify and a clock margin on top, counted by Redis's clock
            // (InboundWebhookReceiver::REPLAY_CLOCK_MARGIN_SECONDS), so the
            // key outlives that window on a worker within the margin.
            return $redis->get($key) === $owner;
        });
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

    public function isShared(): bool
    {
        // One Redis server observed by every worker — a claim is cross-worker.
        return true;
    }

    public function diagnosticName(): string
    {
        return 'redis';
    }
}
