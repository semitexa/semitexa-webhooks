<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures;

use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * A shared replay store whose first claim of signed bytes is stored and then
 * fails: the write reached the store and the answer never came back, as when a
 * worker dies or a connection drops right after the insert. Then a plain set.
 */
final class LostReplyReplayStore implements WebhookReplayStoreInterface
{
    /** @var array<string, true> */
    private array $seen = [];

    private bool $lost = false;

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
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;
        if (!$this->lost && str_starts_with($key, 'inbound-signed:')) {
            $this->lost = true;

            throw new \RuntimeException('the reply was lost');
        }

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
        return 'lost-reply';
    }
}
