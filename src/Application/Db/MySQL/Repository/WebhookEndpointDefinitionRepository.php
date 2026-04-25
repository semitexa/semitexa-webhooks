<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookEndpointDefinitionResourceModel;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

#[SatisfiesRepositoryContract(of: WebhookEndpointDefinitionRepositoryInterface::class)]
final class WebhookEndpointDefinitionRepository implements WebhookEndpointDefinitionRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

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
            ->where(WebhookEndpointDefinitionResourceModel::column('endpointKey'), Operator::Equals, $endpointKey)
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
            ->orderBy(WebhookEndpointDefinitionResourceModel::column('endpointKey'), Direction::Asc)
            ->fetchAllAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    public function findByDirection(string $direction): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->repository()->query()
            ->where(WebhookEndpointDefinitionResourceModel::column('direction'), Operator::Equals, $direction)
            ->where(WebhookEndpointDefinitionResourceModel::column('enabled'), Operator::Equals, true)
            ->orderBy(WebhookEndpointDefinitionResourceModel::column('endpointKey'), Direction::Asc)
            ->fetchAllAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookEndpointDefinitionResourceModel::class,
            WebhookEndpointDefinition::class,
        );
    }

    private function orm(): OrmManager
    {
        if (!isset($this->orm)) {
            throw new \LogicException('WebhookEndpointDefinitionRepository requires OrmManager injection.');
        }

        return $this->orm;
    }
}
