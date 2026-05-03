<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

final class InboundDedupeKeyFactory
{
    public function generate(
        string $providerKey,
        string $endpointKey,
        ?string $providerEventId,
        string $rawBody,
        ?string $tenantId = null,
    ): string {
        $prefix = $tenantId !== null ? "tenant:{$tenantId}:" : '';

        if ($providerEventId !== null && $providerEventId !== '') {
            return "{$prefix}provider:{$providerKey}:endpoint:{$endpointKey}:event:{$providerEventId}";
        }

        $bodyHash = hash('sha256', $rawBody);
        return "{$prefix}provider:{$providerKey}:endpoint:{$endpointKey}:hash:{$bodyHash}";
    }
}
