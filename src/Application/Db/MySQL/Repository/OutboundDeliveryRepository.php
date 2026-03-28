<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookOutboxTableModel;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Enum\OutboundStatus;

#[SatisfiesRepositoryContract(of: OutboundDeliveryRepositoryInterface::class)]
final class OutboundDeliveryRepository implements OutboundDeliveryRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?OutboundDelivery
    {
        /** @var OutboundDelivery|null */
        return $this->repository()->findById($id);
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof OutboundDelivery) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', OutboundDelivery::class, $entity::class));
        }

        if ($this->findById($entity->getId()) === null) {
            $this->repository()->insert($entity);
            return;
        }

        $this->repository()->update($entity);
    }

    public function claimAndLease(string $workerId, \DateTimeImmutable $leaseExpiresAt, int $limit = 1): ?OutboundDelivery
    {
        $now = new \DateTimeImmutable();
        $result = $this->adapter()->execute(
            "UPDATE webhook_outbox
             SET lease_owner = :worker_id,
                 lease_expires_at = :lease_expires_at,
                 status = :status
             WHERE status IN (:pending, :retry_scheduled)
               AND next_attempt_at <= :now
               AND (lease_expires_at IS NULL OR lease_expires_at < :now)
             ORDER BY next_attempt_at ASC
             LIMIT :limit",
            [
                'worker_id' => $workerId,
                'lease_expires_at' => $leaseExpiresAt->format('Y-m-d H:i:s.u'),
                'status' => OutboundStatus::Delivering->value,
                'pending' => OutboundStatus::Pending->value,
                'retry_scheduled' => OutboundStatus::RetryScheduled->value,
                'now' => $now->format('Y-m-d H:i:s.u'),
                'limit' => $limit,
            ],
        );

        if ($result->rowCount === 0) {
            return null;
        }

        /** @var OutboundDelivery|null */
        return $this->repository()->query()
            ->where(WebhookOutboxTableModel::column('leaseOwner'), Operator::Equals, $workerId)
            ->where(WebhookOutboxTableModel::column('status'), Operator::Equals, OutboundStatus::Delivering->value)
            ->orderBy(WebhookOutboxTableModel::column('nextAttemptAt'), Direction::Asc)
            ->fetchOneAs(OutboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        /** @var list<OutboundDelivery> */
        return $this->repository()->query()
            ->where(WebhookOutboxTableModel::column('status'), Operator::Equals, $status)
            ->orderBy(WebhookOutboxTableModel::column('nextAttemptAt'), Direction::Asc)
            ->limit($limit)
            ->fetchAllAs(OutboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $result = $this->adapter()->execute(
            'DELETE FROM webhook_outbox WHERE created_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')],
        );

        return $result->rowCount;
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookOutboxTableModel::class,
            OutboundDelivery::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }
}
