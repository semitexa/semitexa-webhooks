<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookEndpointDefinitionTableModel;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

#[SatisfiesRepositoryContract(of: WebhookEndpointDefinitionRepositoryInterface::class)]
final class WebhookEndpointDefinitionRepository implements WebhookEndpointDefinitionRepositoryInterface
{
    #[InjectAsReadonly]
    protected ?OrmManager $orm = null;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?WebhookEndpointDefinition
    {
        /** @var WebhookEndpointDefinition|null */
        return $this->repository()->findById($id);
    }

    public function findByEndpointKey(string $endpointKey): ?WebhookEndpointDefinition
    {
        /** @var WebhookEndpointDefinition|null */
        return $this->repository()->query()
            ->where(WebhookEndpointDefinitionTableModel::column('endpointKey'), Operator::Equals, $endpointKey)
            ->fetchOneAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof WebhookEndpointDefinition) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %s, got %s.',
                WebhookEndpointDefinition::class,
                $entity::class,
            ));
        }

        if ($this->findById($entity->id) === null) {
            $this->repository()->insert($entity);
            return;
        }

        $this->repository()->update($entity);
    }

    public function findAll(): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->repository()->query()
            ->orderBy(WebhookEndpointDefinitionTableModel::column('endpointKey'), Direction::Asc)
            ->fetchAllAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    public function findByDirection(string $direction): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->repository()->query()
            ->where(WebhookEndpointDefinitionTableModel::column('direction'), Operator::Equals, $direction)
            ->where(WebhookEndpointDefinitionTableModel::column('enabled'), Operator::Equals, true)
            ->orderBy(WebhookEndpointDefinitionTableModel::column('endpointKey'), Direction::Asc)
            ->fetchAllAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookEndpointDefinitionTableModel::class,
            WebhookEndpointDefinition::class,
        );
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
