<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;

/**
 * The MySQL inbox's dedupe contract, in memory: a delivery that failed here is
 * handed back to be processed again, and any other repeat is a duplicate.
 */
final class ReclaimingInbox implements InboundDeliveryRepositoryInterface
{
    /** @var array<string, InboundDelivery> */
    private array $byKey = [];

    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery
    {
        $existing = $this->byKey[$delivery->getDedupeKey()] ?? null;
        if ($existing === null) {
            return $this->byKey[$delivery->getDedupeKey()] = $delivery;
        }
        if ($existing->getStatus() === InboundStatus::Failed) {
            return $existing;
        }
        $copy = clone $existing;
        $copy->markDuplicateIgnored();

        return $copy;
    }

    public function findById(string $id): ?InboundDelivery
    {
        return null;
    }

    public function save(object $entity): void
    {
    }

    public function findByDedupeKey(string $dedupeKey): ?InboundDelivery
    {
        return $this->byKey[$dedupeKey] ?? null;
    }

    public function claimForReplay(string $id, bool $allowProcessed): bool
    {
        return false;
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        return [];
    }

    public function deleteTerminalOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null, ?string $tenantId = null): int
    {
        return 0;
    }

    public function countTerminalOlderThan(\DateTimeImmutable $cutoff, ?string $tenantId = null): int
    {
        return 0;
    }
}
