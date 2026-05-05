<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Auth;

/**
 * Composes replay-protection keys for {@see \Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface}.
 *
 * Tenant scoping:
 *   When a tenant id is provided, the key is prefixed with `tenant:{id}:`
 *   so two tenants posting the same provider event id to the same receiver
 *   namespace (e.g. `webhook-demo:signed:evt-1` from both tenant A and
 *   tenant B) do NOT collide on a shared replay store. The contract
 *   mirrors {@see \Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory}
 *   so the same isolation guarantee covers both the early-edge replay
 *   guard and the persistent inbox dedupe.
 *
 *   Tenant-blind callers (single-tenant deployments, CLI replay, demo
 *   scripts that bypass the tenancy phase) pass `null` and get the bare
 *   `{namespace}:{eventId}` shape — fully backward-compatible with replay
 *   keys written before this factory existed.
 *
 * Cross-backing safety:
 *   Folding tenant into the key string works uniformly across the three
 *   replay store backings (in-memory map, Redis SET NX, MySQL INSERT
 *   IGNORE). No backing-specific schema change is required, and the same
 *   compose() output makes a key portable between backings during a
 *   migration.
 */
final class WebhookReplayKeyFactory
{
    /**
     * @param string $namespace Stable, receiver-defined prefix (e.g.
     *                          `webhook-demo:signed`). Should not include
     *                          the tenant id — that is added here.
     * @param string $eventId   The provider event id from the request
     *                          (typically `X-Webhook-Event-Id`). Caller
     *                          must validate non-empty before calling.
     * @param string|null $tenantId Active tenant id, or null for
     *                              single-tenant / global keys.
     */
    public static function compose(string $namespace, string $eventId, ?string $tenantId = null): string
    {
        $prefix = $tenantId !== null && $tenantId !== '' ? "tenant:{$tenantId}:" : '';
        return $prefix . $namespace . ':' . $eventId;
    }
}
