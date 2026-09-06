<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Webhooks\Application\Service\Outbound\WebhookDeliveryWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:work', description: 'Run the webhook outbound delivery worker')]
final class WebhookWorkCommand extends Command
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

    protected function configure(): void
    {
        $this
            ->setName('webhook:work')
            ->setDescription('Run the webhook outbound delivery worker')
            ->addArgument(
                name: 'worker-id',
                mode: InputArgument::OPTIONAL,
                description: 'Unique worker identifier (default: auto-generated)',
                default: null,
            )
            ->addArgument(
                name: 'poll-interval',
                mode: InputArgument::OPTIONAL,
                description: 'Poll interval in seconds (default: 5)',
                default: '5',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workerId = $input->getArgument('worker-id') ?? 'webhook-worker-' . gethostname() . '-' . getmypid();
        $pollInterval = (int) $input->getArgument('poll-interval');

        $io->title('Webhook delivery worker');

        try {
            $container = $this->container;
            $worker = $container->get(WebhookDeliveryWorker::class);
            $worker->setOutput($output);
            $worker->run($workerId, $pollInterval);
        } catch (\Throwable $e) {
            $io->error('Webhook worker failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
