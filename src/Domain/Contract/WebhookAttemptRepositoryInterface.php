<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\WebhookAttempt;

interface WebhookAttemptRepositoryInterface
{
    /**
     * @param WebhookAttempt $entity
     */
    public function save(object $entity): void;

    /** @return list<WebhookAttempt> */
    public function findByInboxId(string $inboxId): array;

    /** @return list<WebhookAttempt> */
    public function findByOutboxId(string $outboxId): array;

    /**
     * Delete attempt log rows older than $cutoff. Attempts are append-only
     * audit records; truncating them never affects live work. The optional
     * $limit caps the number of rows removed per call so cleanup can be
     * batched.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null): int;

    /**
     * Count attempt rows that {@see deleteOlderThan()} would remove for the
     * given cutoff. Used by dry-run cleanup.
     */
    public function countOlderThan(\DateTimeImmutable $cutoff): int;
}
