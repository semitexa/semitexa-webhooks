<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Fixtures;

use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

/**
 * In-memory {@see WebhookEndpointDefinitionRepositoryInterface} for tests.
 *
 * Endpoint definitions are registered once (via {@see add()}) before any
 * coroutines start and are only ever read concurrently afterwards — plain
 * arrays are safe under Swoole's cooperative scheduling because no method
 * here yields mid-update.
 */
final class InMemoryWebhookEndpointDefinitionRepository implements WebhookEndpointDefinitionRepositoryInterface
{
    /** @var array<string, WebhookEndpointDefinition> keyed by id */
    private array $byId = [];

    /** @var array<string, WebhookEndpointDefinition> keyed by endpointKey */
    private array $byEndpointKey = [];

    public function add(WebhookEndpointDefinition $definition): void
    {
        $this->byId[$definition->getId()] = $definition;
        $this->byEndpointKey[$definition->getEndpointKey()] = $definition;
    }

    public function findById(string $id): ?WebhookEndpointDefinition
    {
        return $this->byId[$id] ?? null;
    }

    public function findByEndpointKey(string $endpointKey): ?WebhookEndpointDefinition
    {
        return $this->byEndpointKey[$endpointKey] ?? null;
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof WebhookEndpointDefinition) {
            throw new \InvalidArgumentException(\sprintf(
                'Expected %s, got %s',
                WebhookEndpointDefinition::class,
                $entity::class,
            ));
        }
        $this->add($entity);
    }

    /** @return list<WebhookEndpointDefinition> */
    public function findAll(): array
    {
        return array_values($this->byId);
    }

    /** @return list<WebhookEndpointDefinition> */
    public function findByDirection(string $direction): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (WebhookEndpointDefinition $d): bool => $d->getDirection()->value === $direction,
        ));
    }
}
