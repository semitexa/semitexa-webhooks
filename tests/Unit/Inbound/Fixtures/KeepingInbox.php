<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;

/** An inbox that keeps every delivery under its dedupe key and finds no duplicate. */
final class KeepingInbox implements InboundDeliveryRepositoryInterface
{
    /** @var array<string, InboundDelivery> */
    public array $byKey = [];

    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery
    {
        return $this->byKey[$delivery->getDedupeKey()] = $delivery;
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
