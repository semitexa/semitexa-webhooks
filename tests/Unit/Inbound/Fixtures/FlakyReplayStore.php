<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/** A shared replay store whose first claim fails, as a database with no table yet does; then a plain set. */
final class FlakyReplayStore implements WebhookReplayStoreInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    private bool $failed = false;

    public function seen(string $key): bool
    {
        return isset($this->seen[$key]);
    }

    public function markSeen(string $key): void
    {
        $this->seen[$key] = true;
    }

    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        if (!$this->failed) {
            $this->failed = true;

            throw new \RuntimeException('replay store is down');
        }
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
        return 'flaky';
    }
}
