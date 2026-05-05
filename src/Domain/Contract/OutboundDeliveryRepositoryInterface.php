<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\OutboundDelivery;

interface OutboundDeliveryRepositoryInterface
{
    public function findById(string $id): ?OutboundDelivery;

    /**
     * @param OutboundDelivery $entity
     */
    public function save(object $entity): void;

    /**
     * Atomic idempotent insert. Two semantics depending on the delivery's
     * idempotencyKey:
     *
     *   - NULL key: every call inserts a fresh row. The optional-idempotency
     *     policy explicitly allows non-deduped publishes.
     *   - Non-null key: the (endpoint_definition_id, idempotency_key) pair
     *     is unique per the schema constraint. The first call inserts; any
     *     subsequent call with the same pair returns the existing row
     *     unchanged. Atomicity is enforced by the database — the call
     *     never executes select-then-insert.
     *
     * Returns the resulting OutboundDelivery — either the input (just
     * inserted) or the pre-existing row (matched). Callers can detect
     * "matched existing" by comparing the returned id to the input id;
     * a difference means the row already existed under another id.
     */
    public function insertOrMatchIdempotency(OutboundDelivery $delivery): OutboundDelivery;

    /**
     * Atomic claim-and-lease: finds a due row (pending / retry_scheduled, or
     * delivering with an EXPIRED lease) and atomically sets lease_owner,
     * lease_expires_at, status='delivering', AND increments attempt_count.
     * Bumping attempt_count inside the same SQL statement is required so
     * concurrent workers cannot race on PHP-side counter increments — every
     * successful claim is exactly one attempt.
     *
     * Returns the claimed delivery (with the bumped attempt_count) or null
     * if none available.
     */
    public function claimAndLease(string $workerId, \DateTimeImmutable $leaseExpiresAt, int $limit = 1): ?OutboundDelivery;

    /**
     * Compare-and-swap finalization: mark the delivery as delivered ONLY if
     * the given $workerId still owns the active lease (status='delivering'
     * AND lease_owner=$workerId). Returns true if the transition committed,
     * false if a stale worker called after losing the lease (e.g. another
     * worker reclaimed an expired lease and is in flight or already
     * finalized). Callers MUST handle the false return — re-running the
     * transport would double-send.
     */
    public function markDeliveredIfOwned(
        string $deliveryId,
        string $workerId,
        int $httpStatus,
        ?string $responseHeadersJson,
        ?string $responseBody,
    ): bool;

    /**
     * Compare-and-swap retry scheduling. Same ownership semantics as
     * {@see markDeliveredIfOwned()}.
     */
    public function markRetryScheduledIfOwned(
        string $deliveryId,
        string $workerId,
        \DateTimeImmutable $nextAttemptAt,
        ?string $error,
    ): bool;

    /**
     * Compare-and-swap permanent failure. Same ownership semantics as
     * {@see markDeliveredIfOwned()}.
     */
    public function markFailedIfOwned(
        string $deliveryId,
        string $workerId,
        ?int $httpStatus,
        ?string $responseBody,
        ?string $error,
    ): bool;

    /**
     * @return list<OutboundDelivery>
     */
    public function findByStatus(string $status, int $limit = 50): array;

    /**
     * Delete outbound deliveries in a TERMINAL status (delivered, failed,
     * cancelled) whose created_at is older than $cutoff. Pending,
     * RetryScheduled and Delivering rows are PRESERVED — those still
     * represent live work. Rows with an unexpired lease are also preserved
     * as defense-in-depth. The optional $limit caps the number of rows
     * removed in a single call so cleanup can be batched without holding a
     * long transaction; null means unbounded.
     *
     * When $tenantId is non-null, the delete is additionally scoped to
     * rows whose `tenant_id` matches; null (the default) means cleanup
     * spans every tenant — appropriate for system-wide retention runs.
     *
     * Returns the number of rows actually deleted.
     */
    public function deleteTerminalOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null, ?string $tenantId = null): int;

    /**
     * Count outbound deliveries that {@see deleteTerminalOlderThan()} would
     * remove for the given cutoff. Used by dry-run cleanup to surface a
     * preview without mutating the table. Same status / lease / tenant
     * filter as the delete method.
     */
    public function countTerminalOlderThan(\DateTimeImmutable $cutoff, ?string $tenantId = null): int;
}
