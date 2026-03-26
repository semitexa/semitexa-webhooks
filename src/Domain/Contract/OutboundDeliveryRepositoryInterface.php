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
     * Atomic claim-and-lease: finds a due row (pending/retry_scheduled, next_attempt_at <= now,
     * no active lease) and atomically sets lease_owner and lease_expires_at.
     * Returns the claimed delivery or null if none available.
     */
    public function claimAndLease(string $workerId, \DateTimeImmutable $leaseExpiresAt, int $limit = 1): ?OutboundDelivery;

    /**
     * @return list<OutboundDelivery>
     */
    public function findByStatus(string $status, int $limit = 50): array;

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
