<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Console;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Webhooks\Outbound\WebhookDeliveryWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:work', description: 'Run the webhook outbound delivery worker')]
final class WebhookWorkCommand extends Command
{
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
            $container = ContainerFactory::get();
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
