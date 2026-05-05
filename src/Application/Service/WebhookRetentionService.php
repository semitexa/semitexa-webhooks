<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;

/**
 * Persistence cleanup orchestration for the webhook pipeline.
 *
 * Driven by `bin/semitexa webhook:cleanup` (or any other cron/job operator
 * wires up). The service is the single place where retention policy is
 * applied — it owns the cutoff calculation, the per-table delete order, and
 * the dry-run / batch-size knobs.
 *
 * Safety contract:
 *   - Outbound: only TERMINAL rows are eligible for deletion (Delivered,
 *     Failed, Cancelled). Pending / RetryScheduled / Delivering rows still
 *     represent live work and are preserved unconditionally. Rows with an
 *     unexpired lease are also preserved as defense-in-depth, so a worker
 *     mid-finalize can never have its row pulled out from under it.
 *   - Inbound: only TERMINAL rows are eligible (Processed, Failed,
 *     RejectedSignature, DuplicateIgnored). Received / Verified / Processing
 *     rows are preserved.
 *   - Attempts: append-only audit log; truncating never affects live work.
 *
 * The replay-key store is intentionally NOT driven from here. Replay-store
 * choice is a deployment decision (in-memory / Redis / MySQL), and only the
 * MySQL backing requires manual cleanup. The CLI command resolves
 * `MySqlWebhookReplayStore` from the container directly when present.
 *
 * Dry-run:
 *   When dry-run is requested, the service runs the same status / cutoff
 *   filter via COUNT(*) instead of DELETE. Returned shape is identical so
 *   the CLI can render the same summary table.
 *
 * Batch size:
 *   $batchSize > 0 caps the per-call delete count via SQL LIMIT. Zero means
 *   unbounded. Operators with very large tables should set a cap so the
 *   delete transaction stays short and the cleanup can be re-run by the
 *   scheduler until the backlog drains.
 */
#[AsService]
final class WebhookRetentionService
{
    #[InjectAsReadonly]
    protected WebhookConfig $config;

    #[InjectAsReadonly]
    protected InboundDeliveryRepositoryInterface $inboxRepo;

    #[InjectAsReadonly]
    protected OutboundDeliveryRepositoryInterface $outboxRepo;

    #[InjectAsReadonly]
    protected WebhookAttemptRepositoryInterface $attemptRepo;

    /**
     * @return array{
     *     inbox: int,
     *     outbox: int,
     *     attempts: int|null,
     *     cutoff: string,
     *     retention_days: int,
     *     batch_size: int,
     *     dry_run: bool,
     *     tenant_id: string|null,
     * }
     */
    public function purge(int $batchSize = 0, bool $dryRun = false, ?int $retentionDaysOverride = null, ?string $tenantId = null): array
    {
        $retentionDays = $retentionDaysOverride ?? $this->config->retentionDays;
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException(
                sprintf('Retention window must be at least 1 day, got %d.', $retentionDays),
            );
        }

        $cutoff = new \DateTimeImmutable("-{$retentionDays} days");
        $limit = $batchSize > 0 ? $batchSize : null;

        if ($dryRun) {
            $inbox = $this->inboxRepo->countTerminalOlderThan($cutoff, $tenantId);
            $outbox = $this->outboxRepo->countTerminalOlderThan($cutoff, $tenantId);
            // Attempts are an append-only audit log with no tenant_id
            // column — the row is scoped to its parent inbox/outbox via FK.
            // Tenant-scoped runs intentionally skip attempts cleanup so a
            // per-tenant cleanup does not silently delete other tenants'
            // attempt records that happen to fall outside the retention
            // window. Operators run a separate global pass for attempts.
            $attempts = $tenantId !== null ? null : $this->attemptRepo->countOlderThan($cutoff);
        } else {
            $inbox = $this->inboxRepo->deleteTerminalOlderThan($cutoff, $limit, $tenantId);
            $outbox = $this->outboxRepo->deleteTerminalOlderThan($cutoff, $limit, $tenantId);
            $attempts = $tenantId !== null ? null : $this->attemptRepo->deleteOlderThan($cutoff, $limit);
        }

        return [
            'inbox' => $inbox,
            'outbox' => $outbox,
            'attempts' => $attempts,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'retention_days' => $retentionDays,
            'batch_size' => $batchSize,
            'dry_run' => $dryRun,
            'tenant_id' => $tenantId,
        ];
    }
}
