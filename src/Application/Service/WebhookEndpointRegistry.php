<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointRegistryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

#[SatisfiesServiceContract(of: WebhookEndpointRegistryInterface::class)]
final class WebhookEndpointRegistry implements WebhookEndpointRegistryInterface
{
    /** @var array<string, WebhookEndpointDefinition> */
    private array $endpoints = [];

    public function register(WebhookEndpointDefinition $definition): void
    {
        $this->endpoints[$definition->endpointKey] = $definition;
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
