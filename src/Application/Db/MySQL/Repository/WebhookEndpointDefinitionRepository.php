<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookEndpointDefinitionResource;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

#[SatisfiesRepositoryContract(of: WebhookEndpointDefinitionRepositoryInterface::class)]
final class WebhookEndpointDefinitionRepository extends AbstractRepository implements WebhookEndpointDefinitionRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return WebhookEndpointDefinitionResource::class;
    }

    public function findById(string $id): ?WebhookEndpointDefinition
    {
        /** @var WebhookEndpointDefinition|null */
        return $this->select()
            ->where($this->getPkColumn(), '=', $this->normalizeId($id))
            ->fetchOne();
    }

    public function findByEndpointKey(string $endpointKey): ?WebhookEndpointDefinition
    {
        /** @var WebhookEndpointDefinition|null */
        return $this->select()
            ->where('endpoint_key', '=', $endpointKey)
            ->fetchOne();
    }

    public function save(WebhookEndpointDefinition $definition): void
    {
        parent::save($definition);
    }

    public function findAll(): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->select()
            ->orderBy('endpoint_key', 'ASC')
            ->fetchAll();
    }

    public function findByDirection(string $direction): array
    {
        /** @var list<WebhookEndpointDefinition> */
        return $this->select()
            ->where('direction', '=', $direction)
            ->where('enabled', '=', 1)
            ->orderBy('endpoint_key', 'ASC')
            ->fetchAll();
    }

    private function normalizeId(string $id): string
    {
        if (strlen($id) === 36 && str_contains($id, '-')) {
            return \Semitexa\Orm\Uuid\Uuid7::toBytes($id);
        }

        return $id;
    }
}
