<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Webhooks\Auth\Contract\WebhookSecretResolverInterface;

/**
 * Default {@see WebhookSecretResolverInterface} backed by environment
 * variables and bare-string literals.
 *
 * Recognized references:
 *   - `env:VAR_NAME` — looked up first via `$_ENV[VAR_NAME]`, then via
 *     `getenv(VAR_NAME)` so deployments whose `variables_order` does not
 *     include "E" still work.
 *   - any other string — returned verbatim (dev / test convenience).
 *
 * Tenant-blind by design: this resolver mirrors the framework's behaviour
 * before {@see WebhookSecretResolverInterface} existed and is therefore
 * safe for single-tenant deployments and demo modules. Multi-tenant
 * production deployments wire their own resolver and use the tenantId
 * argument to pick the right vault / secrets-manager entry.
 */
#[SatisfiesServiceContract(of: WebhookSecretResolverInterface::class)]
final class EnvWebhookSecretResolver implements WebhookSecretResolverInterface
{
    public function resolve(string $secretRef, ?string $tenantId = null): ?string
    {
        if (str_starts_with($secretRef, 'env:')) {
            $envVar = substr($secretRef, 4);
            if (array_key_exists($envVar, $_ENV)) {
                $value = $_ENV[$envVar];
                return is_string($value) ? $value : null;
            }
            $value = getenv($envVar);
            return is_string($value) ? $value : null;
        }

        return $secretRef;
    }
}
