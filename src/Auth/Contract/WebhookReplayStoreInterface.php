<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth\Contract;

/**
 * Per-receiver replay/idempotency store. Records keys (event-id, idempotency-key,
 * or body hash — the receiver chooses) so a duplicate request within the replay
 * window can be detected and rejected.
 *
 * Production deployments wire a DB- or Redis-backed implementation; in-process
 * deployments and tests use {@see InMemoryWebhookReplayStore}. The contract is
 * intentionally narrow — duplicate detection is the only operation, no TTL
 * scan, no "purge expired" — because the tiny demo store can keep entries
 * forever and a real DB-backed store handles expiration via its schema.
 */
interface WebhookReplayStoreInterface
{
    /**
     * Returns true if the key has been seen and recorded by a previous
     * markSeen() call — i.e. this is a replay/duplicate.
     *
     * Concurrency note: do NOT compose seen() + markSeen() in a check-then-act
     * sequence. Two concurrent coroutines / processes can both observe
     * seen() === false before either marks the key. Use {@see markIfFirstSeen()}
     * for the atomic claim semantics every replay handler actually wants.
     */
    public function seen(string $key): bool;

    /**
     * Record the key as processed. Idempotent: calling twice with the same
     * key does not raise — the store simply confirms the seen state.
     */
    public function markSeen(string $key): void;

    /**
     * Atomic check-and-claim. Returns true if THIS call was the first to
     * mark $key, false if some earlier call already did. Use this to gate
     * once-only side effects.
     *
     * Production implementations MUST guarantee atomicity:
     *   - in-memory (single PHP coroutine worker): a single PHP statement
     *     that combines existence-check + assignment is atomic because
     *     coroutines do not preempt mid-statement.
     *   - DB-backed: INSERT ... ON CONFLICT DO NOTHING and inspect the
     *     affected-row count.
     *   - Redis-backed: SET NX EX and inspect the reply.
     *
     * After a successful claim, {@see seen()} returns true on subsequent calls
     * UNTIL $ttlSeconds elapses (if provided and the backing supports expiry).
     *
     * @param int|null $ttlSeconds Replay-window TTL. When provided, the key
     *                             is forgotten after this many seconds and a
     *                             later identical event-id can be processed
     *                             as a fresh delivery (matches the
     *                             AsWebhookReceiver::$replayWindowSeconds
     *                             contract). null = remember forever for the
     *                             lifetime of this store.
     *                             In-memory store honors expiry; Redis uses
     *                             SET NX EX. DB-backed implementations can
     *                             store expires_at and either ignore expired
     *                             rows on lookup or rely on a cleanup job.
     */
    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool;

    /**
     * Test/diagnostic: drop every recorded key. Production implementations
     * may make this a no-op or restrict it to admin tooling.
     */
    public function clear(): void;
}
