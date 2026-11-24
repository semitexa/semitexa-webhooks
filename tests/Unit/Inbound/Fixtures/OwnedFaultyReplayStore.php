<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Auth\Contract\OwnedClaimStoreInterface;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * A shared replay store that records a claim's owner, and fails one claim of
 * signed bytes, whichever way the receiver makes it: the $failOn-th one,
 * either before anything is written (a store that is down) or after the
 * write (the answer lost on its way back).
 */
final class OwnedFaultyReplayStore implements WebhookReplayStoreInterface, OwnedClaimStoreInterface
{
    /** @var array<string, string|null> the key's owner, null when claimed with none */
    private array $owners = [];

    private int $signedClaims = 0;

    /** How many claims named their owner. */
    public int $ownedClaims = 0;

    public function __construct(private readonly int $failOn, private readonly bool $afterWrite) {}

    public function seen(string $key): bool
    {
        return array_key_exists($key, $this->owners);
    }

    public function markSeen(string $key): void
    {
        $this->owners[$key] ??= null;
    }

    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        return $this->claim($key, null);
    }

    public function claimFor(string $key, string $owner, ?int $ttlSeconds = null): bool
    {
        $this->ownedClaims++;

        return $this->claim($key, $owner);
    }

    public function clear(): void
    {
        $this->owners = [];
    }

    public function isShared(): bool
    {
        return true;
    }

    public function diagnosticName(): string
    {
        return 'owned-faulty';
    }

    private function claim(string $key, ?string $owner): bool
    {
        $fails = str_starts_with($key, 'inbound-signed:') && ++$this->signedClaims === $this->failOn;
        if ($fails && !$this->afterWrite) {
            throw new \RuntimeException('replay store is down');
        }
        $first = !array_key_exists($key, $this->owners);
        if ($first) {
            $this->owners[$key] = $owner;
        }
        if ($fails) {
            throw new \RuntimeException('the reply was lost');
        }

        return $first || ($owner !== null && $this->owners[$key] === $owner);
    }
}
