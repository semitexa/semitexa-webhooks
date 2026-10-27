<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Webhooks\Application\Service\WebhookRetentionService;
use Semitexa\Webhooks\Auth\Contract\ExpiringWebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
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
 *   - A store whose keys stay until deleted (it implements
 *     {@see ExpiringWebhookReplayStoreInterface}, as the MySQL store and a
 *     wrapper around it do): this command drives `cleanupExpired()`. Expired
 *     rows (`expires_at <= NOW`) are removed; NULL / future rows are preserved.
 *   - Redis backing: keys expire server-side via TTL — no cleanup work is
 *     scheduled and the command notes this.
 *   - In-memory backing: test-only — no production scheduling.
 *
 * The store is whatever the container binds to WebhookReplayStoreInterface.
 * With Redis or the in-memory default the table cleanup runs normally and a
 * one-line note says the replay store was skipped.
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
    /**
     * Injected rather than reached for statically. The services below are still
     * resolved lazily inside the command body, and deliberately so: every
     * #[AsCommand] class is instantiated and injected at console boot, so
     * injecting a repository directly would open its connection on every
     * `bin/semitexa` invocation — and a dependency that failed to build would
     * make this command vanish from the list instead of reporting the failure.
     */
    #[InjectAsReadonly]
    protected ContainerInterface $container;

    /** The replay-key cell of a run whose replay store failed; the run then fails too. */
    private const REPLAY_FAILED = '(error)';

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
            $container = $this->container;
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

        // A replay store that could not be built or cleaned keeps its expired
        // keys. The table says so, but a scheduler reads only the exit status:
        // it reported success, and nobody looked.
        return $replayLine === self::REPLAY_FAILED ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve the bound replay store and clean it when its keys need deleting.
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
            // The bound store, not the class: the MySQL store takes its
            // OrmManager through the constructor, so asking the container for
            // the class itself always failed and replay keys were never cleaned.
            $store = $this->container->get(WebhookReplayStoreInterface::class);
        } catch (\Throwable $e) {
            // Something is bound and could not be built: say so, it is not "no backing".
            $io->writeln('<error>Replay store could not be built: ' . $e->getMessage() . '</error>');
            return self::REPLAY_FAILED;
        }

        if (!$store instanceof ExpiringWebhookReplayStoreInterface) {
            $io->writeln('<comment>Replay store: the bound store expires its own keys (or keeps none) — skipped.</comment>');
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
            return self::REPLAY_FAILED;
        }
    }
}
