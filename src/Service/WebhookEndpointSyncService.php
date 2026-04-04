<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointRegistryInterface;

final class WebhookEndpointSyncService
{
    #[InjectAsReadonly]
    protected WebhookEndpointRegistryInterface $registry;

    #[InjectAsReadonly]
    protected WebhookEndpointDefinitionRepositoryInterface $repo;

    public function sync(): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->registry->all() as $definition) {
            $existing = $this->repo->findByEndpointKey($definition->endpointKey);

            if ($existing === null) {
                $this->repo->save($definition);
                $created++;
            } else {
                // Update existing if properties differ
                $this->repo->save($definition);
                $updated++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => count($this->registry->all()),
        ];
    }
}
