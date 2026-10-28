<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:replay:outbound', description: 'Reset an outbound delivery to pending for redelivery')]
final class WebhookReplayOutboundCommand extends Command
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
            ->setName('webhook:replay:outbound')
            ->setDescription('Reset an outbound delivery to pending for redelivery')
            ->addArgument(
                name: 'id',
                mode: InputArgument::REQUIRED,
                description: 'UUID of the outbound delivery to replay',
            )
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also resend a delivery that was already delivered');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $id a required argument */
        $id = $input->getArgument('id');

        try {
            $container = $this->container;
            /** @var OutboundDeliveryRepositoryInterface $outboxRepo */
            $outboxRepo = $container->get(OutboundDeliveryRepositoryInterface::class);
            /** @var WebhookAttemptRepositoryInterface $attemptRepo */
            $attemptRepo = $container->get(WebhookAttemptRepositoryInterface::class);

            $delivery = $outboxRepo->findById($id);
            if ($delivery === null) {
                $io->error("Outbound delivery not found: {$id}");
                return Command::FAILURE;
            }

            // Delivered means the receiver answered 2xx: sending it again is a
            // second event on their side, so it takes --force.
            if ($delivery->getStatus() === OutboundStatus::Delivered && !$input->getOption('force')) {
                $io->error("Outbound delivery {$id} was already delivered. Pass --force to send it again.");
                return Command::FAILURE;
            }

            $statusBefore = $delivery->getStatus()->value;
            // The reset decides, by the database's clock: a worker holding a
            // live lease is sending it right now, and resetting it stripped
            // the lease so the next worker sent it a second time. A lease
            // judged here by PHP's clock refused a reset the database would
            // have accepted. The row is read again only to say why.
            if (!$outboxRepo->resetToPendingUnlessLeased($delivery->getId(), (bool) $input->getOption('force'))) {
                $io->error($this->refusal($id, $outboxRepo->findById($id)));
                return Command::FAILURE;
            }
            $delivery->resetToPending();

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

    private function refusal(string $id, ?OutboundDelivery $current): string
    {
        $lease = $current?->getLeaseExpiresAt();

        return match (true) {
            $current === null => "Outbound delivery not found: {$id}",
            $current->getStatus() === OutboundStatus::Delivering && $lease !== null
                => "Outbound delivery {$id} is being sent by {$current->getLeaseOwner()} until {$lease->format('H:i:s')} UTC; try again after that.",
            $current->getStatus() === OutboundStatus::Delivered
                => "Outbound delivery {$id} was already delivered. Pass --force to send it again.",
            default => "Outbound delivery {$id} could not be reset; try again.",
        };
    }
}
