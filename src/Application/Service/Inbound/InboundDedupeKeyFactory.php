<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

use Semitexa\Core\Attribute\AsService;

#[AsService]
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

        $bodyHash = hash('sha256', $rawBody);

        // The event id is a header nobody signs: on its own, a captured signed
        // request re-sent under a coming event's id took that id's key, and
        // the genuine event was ignored as its duplicate. Bound to the body,
        // a provider's retry of the same event still matches; another body
        // under the same id does not. Hashed together, so a long id cannot
        // push the key past its column.
        if ($providerEventId !== null && $providerEventId !== '') {
            return "{$prefix}provider:{$providerKey}:endpoint:{$endpointKey}:event:" . hash('sha256', $providerEventId . "\0" . $bodyHash);
        }

        return "{$prefix}provider:{$providerKey}:endpoint:{$endpointKey}:hash:{$bodyHash}";
    }
}
