<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookAttemptResource;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;

#[SatisfiesRepositoryContract(of: WebhookAttemptRepositoryInterface::class)]
final class WebhookAttemptRepository extends AbstractRepository implements WebhookAttemptRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return WebhookAttemptResource::class;
    }

    public function save(WebhookAttempt $attempt): void
    {
        parent::save($attempt);
    }

    public function findByInboxId(string $inboxId): array
    {
        /** @var list<WebhookAttempt> */
        return $this->select()
            ->where('inbox_id', '=', $this->normalizeId($inboxId))
            ->where('direction', '=', 'inbound')
            ->orderBy('created_at', 'ASC')
            ->fetchAll();
    }

    public function findByOutboxId(string $outboxId): array
    {
        /** @var list<WebhookAttempt> */
        return $this->select()
            ->where('outbox_id', '=', $this->normalizeId($outboxId))
            ->where('direction', '=', 'outbound')
            ->orderBy('created_at', 'ASC')
            ->fetchAll();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->delete()
            ->where('created_at', '<', $cutoff->format('Y-m-d H:i:s.u'))
            ->execute();
    }

    private function normalizeId(string $id): string
    {
        if (strlen($id) === 36 && str_contains($id, '-')) {
            return Uuid7::toBytes($id);
        }

        return $id;
    }
}
