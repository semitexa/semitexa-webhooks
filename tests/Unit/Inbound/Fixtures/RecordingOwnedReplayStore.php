<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Auth\Contract\OwnedClaimStoreInterface;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * A shared replay store that records a claim's owner, and keeps the TTL every
 * claim was made with.
 */
final class RecordingOwnedReplayStore implements WebhookReplayStoreInterface, OwnedClaimStoreInterface
{
    /** @var array<string, string|true> the owner, true when claimed with none */
    private array $seen = [];

    /** @var list<array{key: string, ttl: int|null}> every claim, in order */
    public array $claims = [];

    public function seen(string $key): bool
    {
        return isset($this->seen[$key]);
    }

    public function markSeen(string $key): void
    {
        $this->seen[$key] ??= true;
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

    public function claimFor(string $key, string $owner, ?int $ttlSeconds = null): bool
    {
        $this->claims[] = ['key' => $key, 'ttl' => $ttlSeconds];
        $this->seen[$key] ??= $owner;

        return $this->seen[$key] === $owner;
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
        return 'recording-owned';
    }
}
