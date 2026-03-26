<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookOutboxResource;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Enum\OutboundStatus;

#[SatisfiesRepositoryContract(of: OutboundDeliveryRepositoryInterface::class)]
final class OutboundDeliveryRepository extends AbstractRepository implements OutboundDeliveryRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return WebhookOutboxResource::class;
    }

    public function findById(int|string $id): ?OutboundDelivery
    {
        /** @var OutboundDelivery|null */
        return $this->select()
            ->where($this->getPkColumn(), '=', $this->normalizeId($id))
            ->fetchOne();
    }

    public function save(object $delivery): void
    {
        parent::save($delivery);
    }

    public function claimAndLease(string $workerId, \DateTimeImmutable $leaseExpiresAt, int $limit = 1): ?OutboundDelivery
    {
        $now = new \DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s.u');

        // Raw SQL for atomic claim to prevent double-claiming under concurrency
        $table = 'webhook_outbox';
        $sql = <<<SQL
            UPDATE {$table}
            SET lease_owner = ?, lease_expires_at = ?, status = ?
            WHERE status IN (?, ?)
              AND next_attempt_at <= ?
              AND (lease_expires_at IS NULL OR lease_expires_at < ?)
            ORDER BY next_attempt_at ASC
            LIMIT ?
        SQL;

        $affectedId = $this->rawUpdate($sql, [
            $workerId,
            $leaseExpiresAt->format('Y-m-d H:i:s.u'),
            OutboundStatus::Delivering->value,
            OutboundStatus::Pending->value,
            OutboundStatus::RetryScheduled->value,
            $nowStr,
            $nowStr,
            $limit,
        ]);

        if ($affectedId === 0) {
            return null;
        }

        // Fetch the claimed row
        /** @var OutboundDelivery|null */
        return $this->select()
            ->where('lease_owner', '=', $workerId)
            ->where('status', '=', OutboundStatus::Delivering->value)
            ->orderBy('next_attempt_at', 'ASC')
            ->fetchOne();
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        /** @var list<OutboundDelivery> */
        return $this->select()
            ->where('status', '=', $status)
            ->orderBy('next_attempt_at', 'ASC')
            ->limit($limit)
            ->fetchAll();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->delete()
            ->where('created_at', '<', $cutoff->format('Y-m-d H:i:s.u'))
            ->execute();
    }

    private function normalizeId(string $id): string
    {
        if (strlen($id) === 36 && str_contains($id, '-')) {
            return Uuid7::toBytes($id);
        }

        return $id;
    }
}
