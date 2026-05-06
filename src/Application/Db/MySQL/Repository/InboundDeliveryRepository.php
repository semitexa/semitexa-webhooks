<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookInboxResourceModel;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;

#[SatisfiesRepositoryContract(of: InboundDeliveryRepositoryInterface::class)]
final class InboundDeliveryRepository implements InboundDeliveryRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?InboundDelivery
    {
        /** @var InboundDelivery|null */
        return $this->repository()->findById($id);
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof InboundDelivery) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', InboundDelivery::class, $entity::class));
        }

        if ($this->findById($entity->getId()) === null) {
            $this->repository()->insert($entity);
            return;
        }

        $this->repository()->update($entity);
    }

    public function insertOrMatchDedupe(InboundDelivery $delivery): InboundDelivery
    {
        // Atomic insert-or-match. A check-then-act sequence
        // (findByDedupeKey then insert) is race-prone — two concurrent
        // workers could both observe "not found" and both insert, producing
        // duplicate rows for the same logical event.
        //
        // Atomicity comes from the schema-level UNIQUE constraint on
        // webhook_inbox.dedupe_key (declared on WebhookInboxResourceModel via
        // #[Index(columns: 'dedupe_key', unique: true)]). The INSERT itself
        // is the atomic check-and-claim — exactly one writer wins; the
        // loser's INSERT raises a duplicate-key violation, which we then
        // convert into the "matched existing" branch by re-fetching the row
        // that won.
        try {
            /** @var InboundDelivery */
            return $this->repository()->insert($delivery);
        } catch (\Throwable $e) {
            if (!$this->isDuplicateKeyError($e)) {
                // Real DB error (table missing, syntax, permission, connection
                // failure). Only an actual unique-constraint violation maps
                // to a match — every other error must surface loudly so
                // callers see a real failure instead of a silent "matched"
                // that masks broken infrastructure.
                throw $e;
            }
        }

        $existing = $this->findByDedupeKey($delivery->getDedupeKey());
        if ($existing === null) {
            // Vanishingly rare race: the row was inserted (which is why we got
            // a unique-constraint violation) but is gone before we could read
            // it (cleanup job, manual delete). The insertOrMatchDedupe contract
            // cannot be honored without an existing row, so re-throw rather
            // than silently swallow.
            throw new \RuntimeException(sprintf(
                'Inbound dedupe race: INSERT failed with duplicate key but no row found for dedupeKey "%s"',
                $delivery->getDedupeKey(),
            ));
        }

        $existing->markDuplicateIgnored();
        $this->repository()->update($existing);
        return $existing;
    }

    /**
     * Detect MySQL's unique-constraint violation across the layers the ORM
     * may wrap a PDOException in. Both PDO's standard SQLSTATE 23000 and
     * MySQL's vendor error code 1062 are checked, including any wrapping in
     * a previous-exception chain (the writeEngine may rethrow as a generic
     * RuntimeException).
     */
    private function isDuplicateKeyError(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException) {
                if (($current->errorInfo[0] ?? null) === '23000') {
                    return true;
                }
                if (($current->errorInfo[1] ?? null) === 1062) {
                    return true;
                }
            }
            $code = (string) $current->getCode();
            $message = $current->getMessage();
            if ($code === '23000' || str_contains($message, 'SQLSTATE[23000]')) {
                return true;
            }
            if (str_contains($message, '1062') && str_contains($message, 'Duplicate')) {
                return true;
            }
        }
        return false;
    }

    public function findByDedupeKey(string $dedupeKey): ?InboundDelivery
    {
        /** @var InboundDelivery|null */
        return $this->repository()->query()
            ->where(WebhookInboxResourceModel::column('dedupeKey'), Operator::Equals, $dedupeKey)
            ->fetchOneAs(InboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        /** @var list<InboundDelivery> */
        return $this->repository()->query()
            ->where(WebhookInboxResourceModel::column('status'), Operator::Equals, $status)
            ->orderBy(WebhookInboxResourceModel::column('lastReceivedAt'), Direction::Asc)
            ->limit($limit)
            ->fetchAllAs(InboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function deleteTerminalOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null, ?string $tenantId = null): int
    {
        $sql = 'DELETE FROM webhook_inbox
                WHERE created_at < :cutoff
                  AND status IN (:processed, :failed, :rejected_signature, :duplicate_ignored)';
        $params = [
            'cutoff'             => $cutoff->format('Y-m-d H:i:s.u'),
            'processed'          => InboundStatus::Processed->value,
            'failed'             => InboundStatus::Failed->value,
            'rejected_signature' => InboundStatus::RejectedSignature->value,
            'duplicate_ignored'  => InboundStatus::DuplicateIgnored->value,
        ];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $this->adapter()->execute($sql, $params)->rowCount;
    }

    public function countTerminalOlderThan(\DateTimeImmutable $cutoff, ?string $tenantId = null): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM webhook_inbox
             WHERE created_at < :cutoff
               AND status IN (:processed, :failed, :rejected_signature, :duplicate_ignored)';
        $params = [
            'cutoff'             => $cutoff->format('Y-m-d H:i:s.u'),
            'processed'          => InboundStatus::Processed->value,
            'failed'             => InboundStatus::Failed->value,
            'rejected_signature' => InboundStatus::RejectedSignature->value,
            'duplicate_ignored'  => InboundStatus::DuplicateIgnored->value,
        ];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }

        $row = $this->adapter()->execute($sql, $params)->fetchOne();

        return (int) ($row['c'] ?? 0);
    }

    private function repository(): DomainRepository
    {
        return $this->repository ??= $this->orm()->repository(
            WebhookInboxResourceModel::class,
            InboundDelivery::class,
        );
    }

    private function orm(): OrmManager
    {
        if (!isset($this->orm)) {
            throw new \LogicException('InboundDeliveryRepository requires OrmManager injection.');
        }

        return $this->orm;
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }
}
