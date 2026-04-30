<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Enum\WebhookDirection;
use Semitexa\Orm\Uuid\Uuid7;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:replay:outbound', description: 'Reset an outbound delivery to pending for redelivery')]
final class WebhookReplayOutboundCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('webhook:replay:outbound')
            ->setDescription('Reset an outbound delivery to pending for redelivery')
            ->addArgument(
                name: 'id',
                mode: InputArgument::REQUIRED,
                description: 'UUID of the outbound delivery to replay',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        try {
            $container = ContainerFactory::get();
            $outboxRepo = $container->get(OutboundDeliveryRepositoryInterface::class);
            $attemptRepo = $container->get(WebhookAttemptRepositoryInterface::class);

            $delivery = $outboxRepo->findById($id);
            if ($delivery === null) {
                $io->error("Outbound delivery not found: {$id}");
                return Command::FAILURE;
            }

            $statusBefore = $delivery->getStatus()->value;
            $delivery->resetToPending();
            $outboxRepo->save($delivery);

            $attemptRepo->save(new WebhookAttempt(
                id: Uuid7::generate(),
                direction: WebhookDirection::Outbound,
                inboxId: null,
                outboxId: $delivery->getId(),
                eventType: 'replayed',
                attemptNumber: null,
                statusBefore: $statusBefore,
                statusAfter: $delivery->getStatus()->value,
                workerId: 'cli:webhook:replay:outbound',
                httpStatus: null,
                message: 'Manual replay — reset to pending',
                details: null,
            ));

            $io->success("Outbound delivery {$id} reset to pending. A worker will pick it up.");
        } catch (\Throwable $e) {
            $io->error("Replay failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
