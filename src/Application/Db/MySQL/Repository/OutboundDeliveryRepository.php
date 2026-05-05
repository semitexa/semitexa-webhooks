<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Repository;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookOutboxResourceModel;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;

#[SatisfiesRepositoryContract(of: OutboundDeliveryRepositoryInterface::class)]
final class OutboundDeliveryRepository implements OutboundDeliveryRepositoryInterface
{
    #[InjectAsReadonly]
    protected OrmManager $orm;

    private ?DomainRepository $repository = null;

    public function findById(string $id): ?OutboundDelivery
    {
        /** @var OutboundDelivery|null */
        return $this->repository()->findById($id);
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof OutboundDelivery) {
            throw new \InvalidArgumentException(sprintf('Expected %s, got %s.', OutboundDelivery::class, $entity::class));
        }

        try {
            $this->repository()->insert($entity);
            return;
        } catch (\Throwable $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }

        $this->repository()->update($entity);
    }

    public function insertOrMatchIdempotency(OutboundDelivery $delivery): OutboundDelivery
    {
        // Optional-idempotency policy: NULL keys never collide because MySQL
        // UNIQUE indexes treat NULLs as distinct. Skip the dedupe round-trip
        // and just insert.
        if ($delivery->getIdempotencyKey() === null) {
            $this->repository()->insert($delivery);
            return $delivery;
        }

        try {
            /** @var OutboundDelivery */
            return $this->repository()->insert($delivery);
        } catch (\Throwable $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }

        $existing = $this->findByEndpointAndIdempotencyKey(
            $delivery->getEndpointDefinitionId(),
            (string) $delivery->getIdempotencyKey(),
        );
        if ($existing === null) {
            // INSERT failed with a unique-constraint violation but the
            // matching row vanished before we could read it (cleanup, manual
            // delete). The contract cannot be honored — surface the failure
            // rather than silently swallow.
            throw new \RuntimeException(sprintf(
                'Outbound publisher race: INSERT failed with duplicate key but no row found for (endpoint=%s, idempotencyKey=%s)',
                bin2hex($delivery->getEndpointDefinitionId()),
                (string) $delivery->getIdempotencyKey(),
            ));
        }
        return $existing;
    }

    private function findByEndpointAndIdempotencyKey(string $endpointDefinitionId, string $idempotencyKey): ?OutboundDelivery
    {
        /** @var OutboundDelivery|null */
        return $this->repository()->query()
            ->where(WebhookOutboxResourceModel::column('endpointDefinitionId'), Operator::Equals, $this->normalizeId($endpointDefinitionId))
            ->where(WebhookOutboxResourceModel::column('idempotencyKey'), Operator::Equals, $idempotencyKey)
            ->fetchOneAs(OutboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function claimAndLease(string $workerId, \DateTimeImmutable $leaseExpiresAt, int $limit = 1): ?OutboundDelivery
    {
        $now = new \DateTimeImmutable();
        // The candidate select only narrows the row set for the conditional
        // UPDATE — it never decides ownership on its own. Ownership is
        // decided by the UPDATE's WHERE clause, which re-checks the
        // claim conditions atomically.
        $candidateIds = $this->selectClaimCandidateIds($now, $limit);
        if ($candidateIds === []) {
            return null;
        }

        // We attempt one candidate at a time so the UPDATE's affected-row
        // count (0 or 1) cleanly indicates whether THIS worker won the
        // claim. Multi-row UPDATE would silently claim N rows but only
        // return one to the caller — those extra claims would sit
        // unprocessed under our worker's lease until it expired.
        foreach ($candidateIds as $candidateId) {
            $result = $this->adapter()->execute(
                "UPDATE webhook_outbox
                 SET lease_owner = :worker_id,
                     lease_expires_at = :lease_expires_at,
                     status = :status_set,
                     attempt_count = attempt_count + 1,
                     last_attempt_at = :now_attempt
                 WHERE id = :id
                   AND (
                       (status IN (:pending, :retry_scheduled)
                        AND next_attempt_at <= :now_due
                        AND (lease_expires_at IS NULL OR lease_expires_at < :now_lease_pending))
                     OR
                       (status = :delivering_check
                        AND lease_expires_at IS NOT NULL
                        AND lease_expires_at < :now_lease_delivering)
                   )",
                [
                    'worker_id' => $workerId,
                    'lease_expires_at' => $leaseExpiresAt->format('Y-m-d H:i:s.u'),
                    'status_set' => OutboundStatus::Delivering->value,
                    'pending' => OutboundStatus::Pending->value,
                    'retry_scheduled' => OutboundStatus::RetryScheduled->value,
                    'delivering_check' => OutboundStatus::Delivering->value,
                    'now_due' => $now->format('Y-m-d H:i:s.u'),
                    'now_lease_pending' => $now->format('Y-m-d H:i:s.u'),
                    'now_lease_delivering' => $now->format('Y-m-d H:i:s.u'),
                    'now_attempt' => $now->format('Y-m-d H:i:s.u'),
                    'id' => $candidateId,
                ],
            );

            if ($result->rowCount === 0) {
                // Another worker claimed this row between SELECT and UPDATE.
                // Try the next candidate.
                continue;
            }

            /** @var OutboundDelivery|null $claimed */
            $claimed = $this->repository()->query()
                ->where(WebhookOutboxResourceModel::column('id'), Operator::Equals, $candidateId)
                ->where(WebhookOutboxResourceModel::column('leaseOwner'), Operator::Equals, $workerId)
                ->fetchOneAs(OutboundDelivery::class, $this->orm()->getMapperRegistry());

            if ($claimed !== null) {
                return $claimed;
            }
            // Vanishingly rare: row was re-claimed AND finalized by another
            // worker between our UPDATE and SELECT. Try the next candidate.
        }

        return null;
    }

    public function markDeliveredIfOwned(
        string $deliveryId,
        string $workerId,
        int $httpStatus,
        ?string $responseHeadersJson,
        ?string $responseBody,
    ): bool {
        $result = $this->adapter()->execute(
            'UPDATE webhook_outbox
             SET status = :delivered,
                 delivered_at = :now,
                 last_response_status = :http_status,
                 last_response_headers_json = :headers,
                 last_response_body = :body,
                 last_error = NULL,
                 lease_owner = NULL,
                 lease_expires_at = NULL
             WHERE id = :id
               AND status = :delivering
               AND lease_owner = :worker_id',
            [
                'delivered' => OutboundStatus::Delivered->value,
                'delivering' => OutboundStatus::Delivering->value,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
                'http_status' => $httpStatus,
                'headers' => $responseHeadersJson,
                'body' => $responseBody,
                'id' => $this->normalizeId($deliveryId),
                'worker_id' => $workerId,
            ],
        );
        return $result->rowCount > 0;
    }

    public function markRetryScheduledIfOwned(
        string $deliveryId,
        string $workerId,
        \DateTimeImmutable $nextAttemptAt,
        ?string $error,
    ): bool {
        $result = $this->adapter()->execute(
            'UPDATE webhook_outbox
             SET status = :retry_scheduled,
                 next_attempt_at = :next_attempt_at,
                 last_error = :error,
                 lease_owner = NULL,
                 lease_expires_at = NULL
             WHERE id = :id
               AND status = :delivering
               AND lease_owner = :worker_id',
            [
                'retry_scheduled' => OutboundStatus::RetryScheduled->value,
                'delivering' => OutboundStatus::Delivering->value,
                'next_attempt_at' => $nextAttemptAt->format('Y-m-d H:i:s.u'),
                'error' => $error,
                'id' => $this->normalizeId($deliveryId),
                'worker_id' => $workerId,
            ],
        );
        return $result->rowCount > 0;
    }

    public function markFailedIfOwned(
        string $deliveryId,
        string $workerId,
        ?int $httpStatus,
        ?string $responseBody,
        ?string $error,
    ): bool {
        $result = $this->adapter()->execute(
            'UPDATE webhook_outbox
             SET status = :failed,
                 last_response_status = :http_status,
                 last_response_body = :body,
                 last_error = :error,
                 lease_owner = NULL,
                 lease_expires_at = NULL
             WHERE id = :id
               AND status = :delivering
               AND lease_owner = :worker_id',
            [
                'failed' => OutboundStatus::Failed->value,
                'delivering' => OutboundStatus::Delivering->value,
                'http_status' => $httpStatus,
                'body' => $responseBody,
                'error' => $error,
                'id' => $this->normalizeId($deliveryId),
                'worker_id' => $workerId,
            ],
        );
        return $result->rowCount > 0;
    }

    /**
     * Convert a UUID-formatted id (the form the ORM hydrator returns from
     * BINARY(16) columns) into the raw 16-byte form that PDO must bind to
     * compare against the column. Pass-through for ids that are already
     * raw bytes.
     */
    private function normalizeId(string $id): string
    {
        if (strlen($id) === 36 && str_contains($id, '-')) {
            return Uuid7::toBytes($id);
        }
        return $id;
    }

    public function findByStatus(string $status, int $limit = 50): array
    {
        /** @var list<OutboundDelivery> */
        return $this->repository()->query()
            ->where(WebhookOutboxResourceModel::column('status'), Operator::Equals, $status)
            ->orderBy(WebhookOutboxResourceModel::column('nextAttemptAt'), Direction::Asc)
            ->limit($limit)
            ->fetchAllAs(OutboundDelivery::class, $this->orm()->getMapperRegistry());
    }

    public function deleteTerminalOlderThan(\DateTimeImmutable $cutoff, ?int $limit = null, ?string $tenantId = null): int
    {
        $sql = 'DELETE FROM webhook_outbox
                WHERE created_at < :cutoff
                  AND status IN (:delivered, :failed, :cancelled)
                  AND (lease_expires_at IS NULL OR lease_expires_at <= :now_lease)';
        $params = [
            'cutoff'    => $cutoff->format('Y-m-d H:i:s.u'),
            'delivered' => OutboundStatus::Delivered->value,
            'failed'    => OutboundStatus::Failed->value,
            'cancelled' => OutboundStatus::Cancelled->value,
            'now_lease' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
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
        $sql = 'SELECT COUNT(*) AS c FROM webhook_outbox
             WHERE created_at < :cutoff
               AND status IN (:delivered, :failed, :cancelled)
               AND (lease_expires_at IS NULL OR lease_expires_at <= :now_lease)';
        $params = [
            'cutoff'    => $cutoff->format('Y-m-d H:i:s.u'),
            'delivered' => OutboundStatus::Delivered->value,
            'failed'    => OutboundStatus::Failed->value,
            'cancelled' => OutboundStatus::Cancelled->value,
            'now_lease' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
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
            WebhookOutboxResourceModel::class,
            OutboundDelivery::class,
        );
    }

    private function orm(): OrmManager
    {
        if (!isset($this->orm)) {
            throw new \LogicException('OutboundDeliveryRepository requires OrmManager injection.');
        }

        return $this->orm;
    }

    private function adapter(): \Semitexa\Orm\Adapter\DatabaseAdapterInterface
    {
        return $this->orm()->getAdapter();
    }

    /**
     * @return list<string>
     */
    private function selectClaimCandidateIds(\DateTimeImmutable $now, int $limit): array
    {
        // Two candidate sources:
        //  1) Pending or RetryScheduled rows whose nextAttemptAt has arrived
        //     and which carry no active lease.
        //  2) Delivering rows whose lease has expired — the previous owner is
        //     either dead, hung, or partitioned. Reclaiming such a row is
        //     part of the retry contract; the conditional UPDATE in
        //     claimAndLease() only commits if the row is still in either
        //     of these states by the time the UPDATE executes.
        $rows = $this->adapter()->execute(
            'SELECT id
             FROM webhook_outbox
             WHERE (
                 (status IN (:pending, :retry_scheduled)
                  AND next_attempt_at <= :now_due
                  AND (lease_expires_at IS NULL OR lease_expires_at < :now_lease_pending))
               OR
                 (status = :delivering
                  AND lease_expires_at IS NOT NULL
                  AND lease_expires_at < :now_lease_delivering)
             )
             ORDER BY next_attempt_at ASC
             LIMIT :limit',
            [
                'pending' => OutboundStatus::Pending->value,
                'retry_scheduled' => OutboundStatus::RetryScheduled->value,
                'delivering' => OutboundStatus::Delivering->value,
                'now_due' => $now->format('Y-m-d H:i:s.u'),
                'now_lease_pending' => $now->format('Y-m-d H:i:s.u'),
                'now_lease_delivering' => $now->format('Y-m-d H:i:s.u'),
                'limit' => max(1, $limit),
            ],
        )->fetchAll();

        return array_values(array_filter(array_map(
            static fn(array $row): ?string => isset($row['id']) ? (string) $row['id'] : null,
            $rows,
        )));
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
