<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * A shared replay store that keeps a key as the database store's column does:
 * cut at 191 characters, as INSERT IGNORE cuts a longer value.
 */
final class CuttingReplayStore implements WebhookReplayStoreInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    public function seen(string $key): bool
    {
        return isset($this->seen[substr($key, 0, 191)]);
    }

    public function markSeen(string $key): void
    {
        $this->seen[substr($key, 0, 191)] = true;
    }

    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        $key = substr($key, 0, 191);
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;

        return true;
    }

    public function clear(): void
    {
        $this->seen = [];
    }

    public function isShared(): bool
    {
        return true;
    }

    public function diagnosticName(): string
    {
        return 'cutting';
    }
}
