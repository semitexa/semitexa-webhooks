<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointRegistryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

/**
 * An in-memory list of endpoint definitions, one per worker, keyed by endpoint
 * key. Nothing in the framework fills or reads it: the publisher, the receiver
 * and `webhook:show` use {@see \Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface}.
 *
 * It is NOT tenant-scoped, on purpose and like the table it mirrors: an
 * endpoint key is unique across all tenants, and an inbound request is matched
 * by key before any tenant is known (the definition is what names the tenant).
 * So register() belongs at worker boot, never in a request, and all() returns
 * every tenant's definitions, each with its tenantId and secretRef: a caller
 * that shows them to a tenant filters by getTenantId() itself.
 */
#[SatisfiesServiceContract(of: WebhookEndpointRegistryInterface::class)]
final class WebhookEndpointRegistry implements WebhookEndpointRegistryInterface
{
    /** @var array<string, WebhookEndpointDefinition> */
    #[WorkerState('Endpoint definitions keyed by endpoint key, unique across tenants as in the table, to be registered at worker boot. HAZARD: one list for all tenants; all() returns every definition with its tenantId and secretRef, unfiltered, so a caller serving a tenant must filter by getTenantId(), and register() must not be called from a request.')]
    private array $endpoints = [];

    public function register(WebhookEndpointDefinition $definition): void
    {
        $this->endpoints[$definition->getEndpointKey()] = $definition;
    }

    public function all(): array
    {
        return array_values($this->endpoints);
    }

    public function findByKey(string $endpointKey): ?WebhookEndpointDefinition
    {
        return $this->endpoints[$endpointKey] ?? null;
    }
}
