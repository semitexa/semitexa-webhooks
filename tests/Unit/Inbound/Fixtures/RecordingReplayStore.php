<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * A shared replay store that records no owner, as a project's own may: the
 * receiver falls back to two keys for it. It keeps the TTL of every claim.
 */
final class RecordingReplayStore implements WebhookReplayStoreInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    /** @var list<array{key: string, ttl: int|null}> every claim, in order */
    public array $claims = [];

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
        $this->claims[] = ['key' => $key, 'ttl' => $ttlSeconds];
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;

        return true;
    }

    public function clear(): void
    {
        $this->seen = [];
        $this->claims = [];
    }

    public function isShared(): bool
    {
        return true;
    }

    public function diagnosticName(): string
    {
        return 'recording';
    }
}
