<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\SystemScopeToken;
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

    private ?DomainRepository $system = null;

    public function findById(string $id): ?WebhookEndpointDefinition
    {
        /** @var WebhookEndpointDefinition|null */
        return $this->system()->findById($id);
    }

    public function findByEndpointKey(string $endpointKey): ?WebhookEndpointDefinition
    {
        /** @var WebhookEndpointDefinition|null */
        return $this->system()->query()
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
            $this->system()->insert($entity);
            return;
        }

        $this->system()->update($entity);
    }

    public function findAll(): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->system()->query()
            ->orderBy(WebhookEndpointDefinitionResourceModel::column('endpointKey'), Direction::Asc)
            ->fetchAllAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    public function findByDirection(string $direction): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->system()->query()
            ->where(WebhookEndpointDefinitionResourceModel::column('direction'), Operator::Equals, $direction)
            ->where(WebhookEndpointDefinitionResourceModel::column('enabled'), Operator::Equals, true)
            ->orderBy(WebhookEndpointDefinitionResourceModel::column('endpointKey'), Direction::Asc)
            ->fetchAllAs(WebhookEndpointDefinition::class, $this->orm()->getMapperRegistry());
    }

    /**
     * SYSTEM-scope view of the store — deliberately cross-tenant.
     *
     * Every CURRENT caller is infrastructure, not a tenant surface: the inbound
     * receiver resolves an endpoint_key (globally unique) to learn WHICH tenant
     * a webhook belongs to; the outbound publisher/transport and the sync CLI
     * operate the whole installation. The resource is #[TenantScoped], so any
     * FUTURE tenant-facing finder must either call forTenant(...) or copy this
     * token-marked posture consciously — an unscoped query now fails closed
     * instead of silently leaking across tenants.
     */
    private function system(): DomainRepository
    {
        return $this->system ??= $this->repository()->withoutTenantScope(SystemScopeToken::issue());
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
