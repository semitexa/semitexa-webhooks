<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookInboxTableModel;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;

#[SatisfiesRepositoryContract(of: InboundDeliveryRepositoryInterface::class)]
final class InboundDeliveryRepository implements InboundDeliveryRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?InboundDelivery
    {
        /** @var InboundDelivery|null */
        return $this->repository()->findById($id);
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof InboundDelivery) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', InboundDelivery::class, $entity::class));
        }

        if ($this->findById($entity->getId()) === null) {
            $this->repository()->insert($entity);
            return;
        }

        $this->repository()->update($entity);
    }

    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery
    {
        $existing = $this->findByDedupeKey($delivery->getDedupeKey());

        if ($existing !== null) {
            $existing->markDuplicateIgnored();
            $this->repository()->update($existing);

            return $existing;
        }

        /** @var InboundDelivery */
        return $this->repository()->insert($delivery);
    }

    public function findByDedupeKey(string $dedupeKey): ?InboundDelivery
    {
        /** @var InboundDelivery|null */
        return $this->repository()->query()
            ->where(WebhookInboxTableModel::column('dedupeKey'), Operator::Equals, $dedupeKey)
            ->fetchOneAs(InboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        /** @var list<InboundDelivery> */
        return $this->repository()->query()
            ->where(WebhookInboxTableModel::column('status'), Operator::Equals, $status)
            ->orderBy(WebhookInboxTableModel::column('lastReceivedAt'), Direction::Asc)
            ->limit($limit)
            ->fetchAllAs(InboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $result = $this->adapter()->execute(
            'DELETE FROM webhook_inbox WHERE created_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')],
        );

        return $result->rowCount;
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookInboxTableModel::class,
            InboundDelivery::class,
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
