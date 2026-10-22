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
    /** How long a row may stay received or verified before a retry takes it as abandoned. */
    private const int ABANDONED_AFTER_SECONDS = 900;

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

        // A provider sends a delivery again because it failed here: that one
        // is processed again. Any other repeat is counted and reported to the
        // caller as a duplicate; the row keeps the status it earned.
        // Only the counters are written: writing the whole row with status
        // duplicate_ignored overwrote what the first arrival had earned. UTC,
        // as the ORM writes and reads its datetime columns.
        $key = $delivery->getDedupeKey();
        $this->adapter()->execute(
            'UPDATE webhook_inbox SET duplicate_count = duplicate_count + 1, last_received_at = :now WHERE dedupe_key = :dedupe_key',
            ['now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'dedupe_key' => $key],
        );

        // A row still `received` long after it arrived: the request that
        // stored it died before it even recorded the signature (a 500 after
        // the insert). Matched as a duplicate, every retry was ignored and the
        // event never ran. Marked failed, it is claimed below like any.
        // Not `verified`: that is where a delivery received without a
        // processor rests for good, and taking it as abandoned would hand the
        // caller its event a second time.
        if ($existing->getStatus() === InboundStatus::Received) {
            $abandoned = $this->adapter()->execute(
                "UPDATE webhook_inbox SET status = 'failed', last_error = 'Abandoned before processing.'
                 WHERE dedupe_key = :dedupe_key AND status = 'received' AND COALESCE(updated_at, first_received_at) < :stale",
                [
                    'dedupe_key' => $key,
                    'stale' => (new \DateTimeImmutable('-' . self::ABANDONED_AFTER_SECONDS . ' seconds', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ],
            )->rowCount === 1;
            if ($abandoned) {
                $existing = $this->findByDedupeKey($key) ?? $existing;
            }
        }

        if ($existing->getStatus() === InboundStatus::Failed) {
            // A provider's retry of a failure: claimed by one caller only, so
            // two retries arriving together do not both process it. Read back
            // after the claim, so the saves that follow keep the counters.
            $claimed = $this->adapter()->execute(
                // updated_at moves: a claimed row is not abandoned the moment it is claimed.
                "UPDATE webhook_inbox SET status = 'received', updated_at = :now WHERE dedupe_key = :dedupe_key AND status = 'failed'",
                ['dedupe_key' => $key, 'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            )->rowCount === 1;
            $fresh = $claimed ? $this->findByDedupeKey($key) : null;
            if ($fresh !== null) {
                return $fresh;
            }
        }

        // In memory only, to tell the caller; counts as the row now does.
        $existing->markDuplicateIgnored();

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

    /**
     * The adapter of the caller's transaction when there is one: the
     * counter and claim UPDATEs went around it, so a claim made inside a
     * transaction that rolled back stayed committed.
     */
    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getQueryAdapter();
    }

    /** The binary(16) primary key, from the id as the domain spells it. */
    private function replayId(string $id): string
    {
        return strlen($id) === 36 && str_contains($id, '-') ? \Semitexa\Orm\Application\Service\Uuid7::toBytes($id) : $id;
    }

    public function claimForReplay(string $id, bool $allowProcessed): bool
    {
        $stale = (new \DateTimeImmutable('-' . self::ABANDONED_AFTER_SECONDS . ' seconds', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Not a row a request may be working on right now: received and
        // verified move on with plain saves, so a replay taking one of those
        // while a provider retry runs it would run the processor twice. Such
        // rows, and one stuck in processing (a worker that died mid-run, with
        // force), only once they have been still for ABANDONED_AFTER_SECONDS.
        return $this->adapter()->execute(
            "UPDATE webhook_inbox SET status = 'processing', updated_at = :now
             WHERE id = :id AND signature_status = 'verified'
               AND (
                    status = 'failed'
                 OR (status = 'processed' AND :allow_processed = 1)
                 OR (status IN ('received', 'verified') AND COALESCE(updated_at, first_received_at) < :stale_waiting)
                 OR (status = 'processing' AND :allow_stuck = 1 AND COALESCE(processing_started_at, updated_at, first_received_at) < :stale_processing)
               )",
            [
                'id' => $this->replayId($id),
                'allow_processed' => $allowProcessed ? 1 : 0,
                'allow_stuck' => $allowProcessed ? 1 : 0,
                'stale_waiting' => $stale,
                'stale_processing' => $stale,
                'now' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
        )->rowCount === 1;
    }
}
