<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Auth\Contract\ExpiringWebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * The default webhook replay store: MySqlWebhookReplayStore over the ORM's
 * database, the one the inbox lives in, which every worker shares.
 *
 * The in-memory store was the default. As it is not shared, the receiver asked
 * it nothing, so one captured signed request was processed again under every
 * new event id (tk-webhooks-replay-check-off-with-the-default-store). The
 * database store takes its OrmManager by constructor, which a container-managed
 * class cannot, so this class is the binding: the container injects the
 * OrmManager here, and every call goes to the store over it. The table is
 * webhook_replay_keys, an ORM resource that orm:sync creates with the inbox.
 *
 * A project that wants another store (RedisWebhookReplayStore) binds an
 * implementation from one of its own modules, which wins over a package.
 */
#[SatisfiesServiceContract(of: WebhookReplayStoreInterface::class)]
final class DatabaseWebhookReplayStore implements ExpiringWebhookReplayStoreInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    public function seen(string $key): bool
    {
        return $this->store()->seen($key);
    }

    public function markSeen(string $key): void
    {
        $this->store()->markSeen($key);
    }

    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        return $this->store()->markIfFirstSeen($key, $ttlSeconds);
    }

    public function clear(): void
    {
        $this->store()->clear();
    }

    public function isShared(): bool
    {
        // One table every worker sees.
        return true;
    }

    public function diagnosticName(): string
    {
        return 'database';
    }

    public function cleanupExpired(?\DateTimeImmutable $now = null, ?int $limit = null, ?string $tenantId = null): int
    {
        return $this->store()->cleanupExpired($now, $limit, $tenantId);
    }

    public function countExpired(?\DateTimeImmutable $now = null, ?string $tenantId = null): int
    {
        return $this->store()->countExpired($now, $tenantId);
    }

    private function store(): MySqlWebhookReplayStore
    {
        if (!isset($this->orm)) {
            throw new \LogicException('DatabaseWebhookReplayStore is built by the container, which injects its OrmManager; by hand, use new MySqlWebhookReplayStore($orm).');
        }

        return new MySqlWebhookReplayStore($this->orm);
    }
}
