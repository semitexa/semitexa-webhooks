<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookAttemptResourceModel;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;

#[SatisfiesRepositoryContract(of: WebhookAttemptRepositoryInterface::class)]
final class WebhookAttemptRepository implements WebhookAttemptRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

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
            ->where(WebhookAttemptResourceModel::column('inboxId'), Operator::Equals, $inboxId)
            ->where(WebhookAttemptResourceModel::column('direction'), Operator::Equals, 'inbound')
            ->orderBy(WebhookAttemptResourceModel::column('createdAt'), Direction::Asc)
            ->fetchAllAs(WebhookAttempt::class, $this->orm()->getMapperRegistry());
    }

    public function findByOutboxId(string $outboxId): array
    {
        /** @var list<WebhookAttempt> */
        return $this->repository()->query()
            ->where(WebhookAttemptResourceModel::column('outboxId'), Operator::Equals, $outboxId)
            ->where(WebhookAttemptResourceModel::column('direction'), Operator::Equals, 'outbound')
            ->orderBy(WebhookAttemptResourceModel::column('createdAt'), Direction::Asc)
            ->fetchAllAs(WebhookAttempt::class, $this->orm()->getMapperRegistry());
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null): int
    {
        $sql = 'DELETE FROM webhook_attempts WHERE created_at < :cutoff';
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $this->adapter()->execute(
            $sql,
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')],
        )->rowCount;
    }

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        $row = $this->adapter()->execute(
            'SELECT COUNT(*) AS c FROM webhook_attempts WHERE created_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')],
        )->fetchOne();

        return (int) ($row['c'] ?? 0);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookAttemptResourceModel::class,
            WebhookAttempt::class,
        );
    }

    private function orm(): OrmManager
    {
        if (!isset($this->orm)) {
            throw new \LogicException('WebhookAttemptRepository requires OrmManager injection.');
        }

        return $this->orm;
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }
}
