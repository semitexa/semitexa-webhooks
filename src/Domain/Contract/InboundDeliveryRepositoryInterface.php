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

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
