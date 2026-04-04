<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookAttemptTableModel;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;

#[SatisfiesRepositoryContract(of: WebhookAttemptRepositoryInterface::class)]
final class WebhookAttemptRepository implements WebhookAttemptRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function save(object $entity): void
    {
        if (!$entity instanceof WebhookAttempt) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', WebhookAttempt::class, $entity::class));
        }

        $this->repository()->insert($entity);
    }

    public function findByInboxId(string $inboxId): array
    {
        /** @var list<WebhookAttempt> */
        return $this->repository()->query()
            ->where(WebhookAttemptTableModel::column('inboxId'), Operator::Equals, $inboxId)
            ->where(WebhookAttemptTableModel::column('direction'), Operator::Equals, 'inbound')
            ->orderBy(WebhookAttemptTableModel::column('createdAt'), Direction::Asc)
            ->fetchAllAs(WebhookAttempt::class, $this->orm()->getMapperRegistry());
    }

    public function findByOutboxId(string $outboxId): array
    {
        /** @var list<WebhookAttempt> */
        return $this->repository()->query()
            ->where(WebhookAttemptTableModel::column('outboxId'), Operator::Equals, $outboxId)
            ->where(WebhookAttemptTableModel::column('direction'), Operator::Equals, 'outbound')
            ->orderBy(WebhookAttemptTableModel::column('createdAt'), Direction::Asc)
            ->fetchAllAs(WebhookAttempt::class, $this->orm()->getMapperRegistry());
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $result = $this->adapter()->execute(
            'DELETE FROM webhook_attempts WHERE created_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')],
        );

        return $result->rowCount;
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookAttemptTableModel::class,
            WebhookAttempt::class,
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
