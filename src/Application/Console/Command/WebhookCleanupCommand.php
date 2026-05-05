<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Webhooks\Application\Service\WebhookRetentionService;
use Semitexa\Webhooks\Auth\MySqlWebhookReplayStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator-facing cleanup driver. Wires the {@see WebhookRetentionService}
 * (table cleanup) together with the MySQL replay-key cleanup into a single
 * command so cron + admin invocations have one entry point.
 *
 * Replay-store handling is store-aware:
 *   - MySQL backing: this command drives `cleanupExpired()` on the resolved
 *     {@see MySqlWebhookReplayStore}. Expired rows (`expires_at <= NOW`) are
 *     removed; NULL / future rows are preserved.
 *   - Redis backing: keys expire server-side via TTL — no cleanup work is
 *     scheduled and the command notes this.
 *   - In-memory backing: test-only — no production scheduling.
 *
 * The replay store is resolved from the container only if a binding exists
 * for {@see MySqlWebhookReplayStore}. Operators that wire Redis or the
 * in-memory default see the table cleanup run normally and a one-line note
 * for the replay store.
 *
 * Output discipline:
 *   The summary surfaces row counts only — never payload bodies, header
 *   contents, secrets, or any tenant-identifying material. Payload bodies
 *   live in `webhook_inbox.raw_body` and `webhook_outbox.payload_json`; the
 *   command never reads them.
 */
#[AsCommand(name: 'webhook:cleanup', description: 'Purge expired webhook persistence rows (replay keys, inbox, outbox, attempts)')]
final class WebhookCleanupCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('webhook:cleanup')
            ->setDescription('Purge expired webhook persistence rows (replay keys, inbox, outbox, attempts)')
            ->addOption(
                name: 'dry-run',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Report what would be deleted without removing any rows',
            )
            ->addOption(
                name: 'batch-size',
                shortcut: 'b',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Cap the number of rows deleted per table per run (0 = unbounded)',
                default: '1000',
            )
            ->addOption(
                name: 'retention-days',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Override the configured retention window for inbox / outbox / attempts',
                default: null,
            )
            ->addOption(
                name: 'tenant',
                shortcut: null,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Restrict cleanup to a single tenant id (inbox + outbox + tenant-prefixed replay keys). Attempts are skipped in tenant-scoped runs and require a separate global pass.',
                default: null,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = (int) $input->getOption('batch-size');
        $retentionOverride = $input->getOption('retention-days');
        $retentionOverride = $retentionOverride !== null ? (int) $retentionOverride : null;
        $tenantOption = $input->getOption('tenant');
        $tenantId = is_string($tenantOption) && $tenantOption !== '' ? $tenantOption : null;

        if ($batchSize < 0) {
            $io->error('--batch-size must be >= 0 (0 = unbounded).');
            return Command::FAILURE;
        }
        if ($retentionOverride !== null && $retentionOverride < 1) {
            $io->error('--retention-days must be >= 1 day.');
            return Command::FAILURE;
        }

        try {
            $container = ContainerFactory::get();
            $service = $container->get(WebhookRetentionService::class);
            $summary = $service->purge($batchSize, $dryRun, $retentionOverride, $tenantId);
        } catch (\Throwable $e) {
            $io->error('Cleanup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $replayLine = $this->cleanupReplayKeys($io, $dryRun, $batchSize, $tenantId);

        $title = $dryRun ? 'Webhook cleanup — DRY RUN' : 'Webhook cleanup';
        if ($tenantId !== null) {
            $title .= " (tenant: {$tenantId})";
        }
        $io->title($title);
        $io->definitionList(
            ['Cutoff' => $summary['cutoff']],
            ['Retention' => $summary['retention_days'] . ' days'],
            ['Batch size' => $batchSize === 0 ? 'unbounded' : (string) $batchSize],
            ['Tenant scope' => $tenantId ?? 'all tenants'],
            ['Mode' => $dryRun ? 'dry-run (no rows changed)' : 'apply'],
        );

        $verb = $dryRun ? 'eligible' : 'deleted';
        $attemptsCell = $summary['attempts'] === null
            ? '— (skipped in tenant-scoped run)'
            : (string) $summary['attempts'];
        $io->table(
            ['Table', "Rows {$verb}"],
            [
                ['webhook_replay_keys', $replayLine],
                ['webhook_inbox (terminal only)', (string) $summary['inbox']],
                ['webhook_outbox (terminal only)', (string) $summary['outbox']],
                ['webhook_attempts', $attemptsCell],
            ],
        );

        if ($dryRun) {
            $io->note('No rows were removed. Re-run without --dry-run to apply.');
        }
        if ($tenantId !== null) {
            $io->note('Tenant-scoped run: webhook_attempts retention is global; run without --tenant for an attempts-only pass.');
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve the MySQL replay store if wired and run cleanup against it.
     * Returns a string suitable for the summary table cell — either a row
     * count, a "—" when no MySQL backing is configured, or "(error)" when
     * the call threw.
     *
     * When $tenantId is provided, the cleanup is restricted to keys whose
     * literal value begins with `tenant:{id}:` — the convention emitted by
     * {@see \Semitexa\Webhooks\Application\Service\Auth\WebhookReplayKeyFactory}.
     * Tenant-blind keys (legacy single-tenant deployments, demo scripts that
     * bypass the tenancy phase) are NOT touched in tenant-scoped runs.
     */
    private function cleanupReplayKeys(SymfonyStyle $io, bool $dryRun, int $batchSize, ?string $tenantId = null): string
    {
        try {
            $store = ContainerFactory::get()->get(MySqlWebhookReplayStore::class);
        } catch (\Throwable) {
            $io->writeln('<comment>Replay store: no MySQL backing wired (Redis TTL or in-memory) — skipped.</comment>');
            return '— (no MySQL backing)';
        }

        if (!$store instanceof MySqlWebhookReplayStore) {
            $io->writeln('<comment>Replay store: resolved instance is not MySQL-backed — skipped.</comment>');
            return '— (no MySQL backing)';
        }

        try {
            $limit = $batchSize > 0 ? $batchSize : null;
            $count = $dryRun
                ? $store->countExpired(null, $tenantId)
                : $store->cleanupExpired(null, $limit, $tenantId);
            return (string) $count;
        } catch (\Throwable $e) {
            $io->writeln('<error>Replay store cleanup failed: ' . $e->getMessage() . '</error>');
            return '(error)';
        }
    }
}
