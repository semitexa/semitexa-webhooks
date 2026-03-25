<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

interface WebhookEndpointRegistryInterface
{
    public function register(WebhookEndpointDefinition $definition): void;

    /** @return list<WebhookEndpointDefinition> */
    public function all(): array;

    public function findByKey(string $endpointKey): ?WebhookEndpointDefinition;
}
