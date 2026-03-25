<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Orm\Repository\AbstractRepository;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookInboxResource;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;

#[SatisfiesRepositoryContract(of: InboundDeliveryRepositoryInterface::class)]
final class InboundDeliveryRepository extends AbstractRepository implements InboundDeliveryRepositoryInterface
{
    protected function getResourceClass(): string
    {
        return WebhookInboxResource::class;
    }

    public function findById(string $id): ?InboundDelivery
    {
        /** @var InboundDelivery|null */
        return $this->select()
            ->where($this->getPkColumn(), '=', $this->normalizeId($id))
            ->fetchOne();
    }

    public function save(InboundDelivery $delivery): void
    {
        parent::save($delivery);
    }

    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery
    {
        $existing = $this->findByDedupeKey($delivery->getDedupeKey());

        if ($existing !== null) {
            $existing->markDuplicateIgnored();
            $this->save($existing);
            return $existing;
        }

        $this->save($delivery);
        return $delivery;
    }

    public function findByDedupeKey(string $dedupeKey): ?InboundDelivery
    {
        /** @var InboundDelivery|null */
        return $this->select()
            ->where('dedupe_key', '=', $dedupeKey)
            ->fetchOne();
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        /** @var list<InboundDelivery> */
        return $this->select()
            ->where('status', '=', $status)
            ->orderBy('last_received_at', 'ASC')
            ->limit($limit)
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
