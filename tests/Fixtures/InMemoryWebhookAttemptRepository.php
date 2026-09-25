<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Fixtures;

use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;

/**
 * In-memory {@see WebhookAttemptRepositoryInterface} for tests.
 *
 * {@see save()} is the only method the concurrency stress test's worker
 * coroutines call, and each call appends a single element to a plain PHP
 * array with no yield in between — a coroutine-safe operation under
 * Swoole's cooperative scheduler, since a context switch can only happen
 * at an explicit yield point (channel push/pop, sleep, I/O), never inside
 * a single PHP statement like `$this->attempts[] = $attempt;`.
 */
final class InMemoryWebhookAttemptRepository implements WebhookAttemptRepositoryInterface
{
    /** @var list<WebhookAttempt> */
    private array $attempts = [];

    public function save(object $entity): void
    {
        if (!$entity instanceof WebhookAttempt) {
            throw new \InvalidArgumentException(\sprintf(
                'Expected %s, got %s',
                WebhookAttempt::class,
                $entity::class,
            ));
        }
        $this->attempts[] = $entity;
    }

    /** @return list<WebhookAttempt> */
    public function findByInboxId(string $inboxId): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (WebhookAttempt $a): bool => $a->getInboxId() === $inboxId,
        ));
    }

    /** @return list<WebhookAttempt> */
    public function findByOutboxId(string $outboxId): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (WebhookAttempt $a): bool => $a->getOutboxId() === $outboxId,
        ));
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null): int
    {
        $removed = 0;
        $kept = [];
        foreach ($this->attempts as $attempt) {
            if ($attempt->getCreatedAt() < $cutoff && ($limit === null || $removed < $limit)) {
                $removed++;
                continue;
            }
            $kept[] = $attempt;
        }
        $this->attempts = $kept;
        return $removed;
    }

    public function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        return count(array_filter(
            $this->attempts,
            static fn (WebhookAttempt $a): bool => $a->getCreatedAt() < $cutoff,
        ));
    }

    /** @return list<WebhookAttempt> */
    public function all(): array
    {
        return $this->attempts;
    }
}
