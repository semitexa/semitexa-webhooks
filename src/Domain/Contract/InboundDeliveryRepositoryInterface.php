<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\InboundDelivery;

interface InboundDeliveryRepositoryInterface
{
    public function findById(string $id): ?InboundDelivery;

    /**
     * @param InboundDelivery $entity
     */
    public function save(object $entity): void;

    /**
     * Atomic insert-or-match: inserts a new row if dedupe_key is unique,
     * otherwise updates duplicate_count and last_received_at on the existing row.
     * Returns the current row (new or existing).
     */
    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery;

    public function findByDedupeKey(string $dedupeKey): ?InboundDelivery;

    /**
     * @return list<InboundDelivery>
     */
    public function findByStatus(string $status, int $limit = 50): array;

    /**
     * Delete inbound deliveries in a TERMINAL status (processed, failed,
     * rejected_signature, duplicate_ignored) whose created_at is older than
     * $cutoff. Received, Verified and Processing rows are PRESERVED — those
     * still represent live work. The optional $limit caps the number of
     * rows removed in a single call so cleanup can be batched without
     * holding a long transaction; null means unbounded.
     *
     * When $tenantId is non-null, the delete is additionally scoped to
     * rows whose `tenant_id` matches; null (the default) means cleanup
     * spans every tenant.
     *
     * Returns the number of rows actually deleted.
     */
    public function deleteTerminalOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null, ?string $tenantId = null): int;

    /**
     * Count inbound deliveries that {@see deleteTerminalOlderThan()} would
     * remove for the given cutoff. Used by dry-run cleanup. Same status /
     * tenant filter as the delete method.
     */
    public function countTerminalOlderThan(\DateTimeImmutable $cutoff, ?string $tenantId = null): int;
}
