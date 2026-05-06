<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Core\Auth\AuthenticatableInterface;

/**
 * Service-domain principal representing a successfully signature-verified
 * webhook request. Carried on AuthResult by WebhookAuthHandler.
 *
 * Identity model:
 *   getId() returns the STABLE endpointKey only — never the per-request
 *   eventId or the resolved tenant id. The endpoint key alone identifies
 *   the receiver across many events.
 *
 *   The default `endpointKey` is the payload class short name whenever
 *   #[AsWebhookReceiver] omits the optional `name:` parameter. New
 *   receivers should set `name:` for a stable, class-name-independent
 *   identity.
 *
 * Tenant scoping:
 *   The optional $tenantId field carries the tenant resolved by the
 *   framework's tenancy phase at the time of authentication. Downstream
 *   capability resolution ({@see \Semitexa\Rbac\Domain\Contract\ServiceCapabilityProviderInterface})
 *   reads tenant context from the global `TenantContextStoreInterface`, so
 *   $tenantId on the principal is metadata for audit + handler layers
 *   rather than a load-bearing input to authorization. Keeping it on the
 *   principal makes it visible to attempt logs without forcing a re-read
 *   of the tenant store.
 *
 * AuthorizationListener composes the cache key as
 * `{tenantId}:service:{endpointKey}`, which is also the (serviceId, tenantId)
 * pair passed to ServiceCapabilityProviderInterface.
 */
final readonly class WebhookPrincipal implements AuthenticatableInterface
{
    public function __construct(
        public string $endpointKey,
        public ?string $eventId = null,
        public ?string $tenantId = null,
    ) {}

    public function getId(): string
    {
        // Stable identity — capability grants live at the receiver level,
        // not the per-event level. eventId and tenantId are metadata,
        // not identity. Tenant is folded into authorization decisions via
        // the cache key + provider arg, not via the subject id.
        return $this->endpointKey;
    }

    public function getAuthIdentifierName(): string
    {
        return 'webhook_endpoint_key';
    }

    public function getAuthIdentifier(): string
    {
        return $this->endpointKey;
    }
}
