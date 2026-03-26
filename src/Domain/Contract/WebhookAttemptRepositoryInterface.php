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

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
