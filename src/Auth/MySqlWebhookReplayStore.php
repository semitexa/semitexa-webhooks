<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Orm\OrmManager;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * MySQL-backed production webhook replay store.
 *
 * Atomicity contract:
 *   markIfFirstSeen() uses MySQL's `INSERT IGNORE INTO ... (replay_key, ...)
 *   VALUES (...)`. The PRIMARY KEY constraint on `replay_key` makes the insert
 *   either succeed (affected_rows = 1, we are the first) or fail-and-ignore
 *   (affected_rows = 0, somebody else got there first). The check + write
 *   collapse into a single atomic statement at the database level — every
 *   client (every PHP worker, every PHP process, every machine) competing
 *   on the same key sees a deterministic one-winner result.
 *
 *   InMemoryWebhookReplayStore relies on Swoole's no-mid-statement-
 *   preemption guarantee for atomicity (single-worker only).
 *   RedisWebhookReplayStore relies on Redis's atomic SET NX. This store
 *   relies on the database's primary-key constraint, which is the strongest
 *   of the three because it spans the entire deployment, not a single worker
 *   or a single Redis connection pool.
 *
 * TTL contract — expired keys remain blocking until cleanup:
 *   When $ttlSeconds is provided, an `expires_at` value is stored. Operators
 *   are expected to call cleanupExpired() periodically (cron, background job,
 *   admin command). Until cleanup runs, expired keys CONTINUE TO BLOCK
 *   duplicate inserts — the markIfFirstSeen() call returns false even if the
 *   row's expires_at has passed.
 *
 *   This is intentional and conservative: a delete-then-insert pattern would
 *   reintroduce a check-then-act race (two writers could both observe an
 *   expired row, both delete it, both insert their own — both win, both fire
 *   side effects). Blocking expired keys until cleanup is safer. The replay
 *   window is therefore enforced operationally: cleanup cadence determines
 *   how soon a duplicate event-id is accepted as a fresh delivery after the
 *   window closes.
 *
 * Schema:
 *   `webhook_replay_keys` table — see WebhookReplayKeyResourceModel for the
 *   ORM-managed schema definition. Production deployments run the framework's
 *   schema-sync mechanism (orm:sync / orm:diff) to materialize the table.
 *
 * Service binding:
 *   Intentionally NOT marked with #[SatisfiesServiceContract]. Production
 *   deployments wire this explicitly. Same rationale as RedisWebhookReplayStore:
 *   which database (and which schema) is a deployment decision, not framework
 *   magic. Tests and demos use the in-memory default; production overrides
 *   explicitly.
 *
 * Connection management:
 *   Uses OrmManager::getAdapter()->execute() — every call goes through the
 *   adapter's pooled connection. INSERT IGNORE returns row counts via
 *   QueryResult::$rowCount (PDO's rowCount on MySQL: 1 for inserted, 0 for
 *   ignored).
 */
final class MySqlWebhookReplayStore implements WebhookReplayStoreInterface
{
    public const TABLE = 'webhook_replay_keys';

    public function __construct(private readonly OrmManager $orm) {}

    public function seen(string $key): bool
    {
        $sql = sprintf('SELECT 1 FROM `%s` WHERE replay_key = :key LIMIT 1', self::TABLE);
        $result = $this->orm->getAdapter()->execute($sql, ['key' => $key]);
        return $result->fetchOne() !== null;
    }

    public function markSeen(string $key): void
    {
        // Idempotent: if the row exists already, we leave it alone (including
        // its existing expires_at). Same INSERT IGNORE pattern as
        // markIfFirstSeen but discarding the result.
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = sprintf(
            'INSERT IGNORE INTO `%s` (replay_key, first_seen_at, expires_at) VALUES (:key, :first_seen_at, NULL)',
            self::TABLE,
        );
        $this->orm->getAdapter()->execute($sql, [
            'key'            => $key,
            'first_seen_at'  => $now,
        ]);
    }

    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        $now = new \DateTimeImmutable();
        $expiresAt = $ttlSeconds !== null ? $now->modify('+' . max(0, $ttlSeconds) . ' seconds') : null;

        $sql = sprintf(
            'INSERT IGNORE INTO `%s` (replay_key, first_seen_at, expires_at) VALUES (:key, :first_seen_at, :expires_at)',
            self::TABLE,
        );
        $result = $this->orm->getAdapter()->execute($sql, [
            'key'            => $key,
            'first_seen_at'  => $now->format('Y-m-d H:i:s'),
            'expires_at'     => $expiresAt?->format('Y-m-d H:i:s'),
        ]);

        // INSERT IGNORE returns rowCount = 1 when the row was inserted, 0
        // when it was suppressed by the duplicate-key constraint. That is
        // exactly the atomic check-and-claim semantics this method needs.
        return $result->rowCount > 0;
    }

    /**
     * Test/admin-scoped clear. Wipes EVERY replay key — production code must
     * not call this. Use cleanupExpired() for routine maintenance.
     */
    public function clear(): void
    {
        $this->orm->getAdapter()->execute(sprintf('DELETE FROM `%s`', self::TABLE));
    }

    /**
     * Remove every row whose expires_at is in the past.
     *
     * Returns the number of rows deleted. Operators schedule this periodically
     * (cron, queue job, admin command) to free up replay-key slots so duplicate
     * event-ids past their replay window can be accepted as fresh deliveries.
     *
     * Rows with expires_at = NULL (callers that passed $ttlSeconds = null) are
     * NOT deleted — those keys are intentionally permanent.
     *
     * The optional $limit caps the number of rows removed in a single call so
     * cleanup can be batched without holding a long transaction; null means
     * unbounded.
     *
     * When $tenantId is provided, the delete is restricted to keys whose
     * literal value begins with `tenant:{id}:` — the convention emitted by
     * {@see \Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory}.
     * Keys without a tenant prefix (single-tenant deployments) are NOT
     * affected by a tenant-scoped run; operators run a tenant-blind pass for
     * those.
     */
    public function cleanupExpired(?\DateTimeImmutable $now = null, ?int $limit = null, ?string $tenantId = null): int
    {
        $now ??= new \DateTimeImmutable();
        $sql = sprintf('DELETE FROM `%s` WHERE expires_at IS NOT NULL AND expires_at <= :now', self::TABLE);
        $params = ['now' => $now->format('Y-m-d H:i:s')];
        if ($tenantId !== null) {
            $sql .= ' AND replay_key LIKE :tenant_prefix';
            $params['tenant_prefix'] = self::tenantKeyPrefix($tenantId) . '%';
        }
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $result = $this->orm->getAdapter()->execute($sql, $params);
        return $result->rowCount;
    }

    /**
     * Count rows that {@see cleanupExpired()} would delete at the given $now.
     * Used by dry-run cleanup to surface a preview without mutating the table.
     * Same NULL / future / tenant-prefix filter as the delete method.
     */
    public function countExpired(?\DateTimeImmutable $now = null, ?string $tenantId = null): int
    {
        $now ??= new \DateTimeImmutable();
        $sql = sprintf(
            'SELECT COUNT(*) AS c FROM `%s` WHERE expires_at IS NOT NULL AND expires_at <= :now',
            self::TABLE,
        );
        $params = ['now' => $now->format('Y-m-d H:i:s')];
        if ($tenantId !== null) {
            $sql .= ' AND replay_key LIKE :tenant_prefix';
            $params['tenant_prefix'] = self::tenantKeyPrefix($tenantId) . '%';
        }
        $row = $this->orm->getAdapter()->execute($sql, $params)->fetchOne();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * The literal prefix WebhookReplayKeyFactory uses for a given tenant.
     * Centralized here so the cleanup query and the key factory cannot
     * drift apart silently.
     */
    private static function tenantKeyPrefix(string $tenantId): string
    {
        return 'tenant:' . $tenantId . ':';
    }
}
