<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth\Contract;

/**
 * Pluggable resolution of `#[AsWebhookReceiver(secretRef: ...)]` references
 * to the actual signing secret bytes.
 *
 * The default implementation ({@see \Semitexa\Webhooks\Auth\EnvWebhookSecretResolver})
 * understands `env:VAR_NAME` references and bare-string literals, and is
 * tenant-blind. Multi-tenant production deployments wire their own resolver
 * to look up per-tenant secrets from a vault, secrets manager, or
 * tenant-scoped configuration table — the interface receives the tenant id
 * resolved by the framework's tenancy phase, so a custom resolver can
 * differentiate without re-parsing the request.
 *
 * Resolver contract:
 *   - Return the raw secret bytes (HMAC key material) on success.
 *   - Return null when the reference cannot be resolved (missing env var,
 *     unknown vault key, secrets manager unavailable). The caller treats a
 *     null return as authentication failure — never as "fall through to
 *     another resolver".
 *   - Never throw on a missing reference; throwing is reserved for hard
 *     infrastructure failures (e.g. vault network error) where the caller
 *     wants to surface a 5xx instead of a 401.
 *   - The contract does NOT specify caching. Resolvers that talk to
 *     external systems should cache internally with a TTL appropriate to
 *     their secret-rotation policy.
 */
interface WebhookSecretResolverInterface
{
    /**
     * @param string $secretRef The opaque reference string from
     *                          {@see \Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver::$secretRef}.
     *                          The resolver decides how to interpret it.
     * @param string|null $tenantId The active tenant id, or null when no
     *                              tenant context has been resolved (CLI
     *                              tasks, single-tenant deployments).
     */
    public function resolve(string $secretRef, ?string $tenantId = null): ?string;
}
