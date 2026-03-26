<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

interface WebhookEndpointDefinitionRepositoryInterface
{
    public function findById(string $id): ?WebhookEndpointDefinition;

    public function findByEndpointKey(string $endpointKey): ?WebhookEndpointDefinition;

    public function save(object $entity): void;

    /** @return list<WebhookEndpointDefinition> */
    public function findAll(): array;

    /** @return list<WebhookEndpointDefinition> */
    public function findByDirection(string $direction): array;
}
