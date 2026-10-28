<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth\Contract;

/**
 * A replay store whose expired keys stay until something deletes them (a
 * database table; a store with a native TTL does not need this).
 * `webhook:cleanup` cleans the bound store when it implements this.
 */
interface ExpiringWebhookReplayStoreInterface extends WebhookReplayStoreInterface
{
    public function cleanupExpired(?\DateTimeImmutable $now = null, ?int $limit = null, ?string $tenantId = null): int;

    public function countExpired(?\DateTimeImmutable $now = null, ?string $tenantId = null): int;
}
