<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Enum\WebhookDirection;
use Semitexa\Webhooks\Inbound\InboundWebhookReceiver;
use Semitexa\Orm\Uuid\Uuid7;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:replay:inbound', description: 'Replay an inbound webhook delivery by ID')]
final class WebhookReplayInboundCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('webhook:replay:inbound')
            ->setDescription('Replay an inbound webhook delivery by ID')
            ->addArgument(
                name: 'id',
                mode: InputArgument::REQUIRED,
                description: 'UUID of the inbound delivery to replay',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        try {
            $container = ContainerFactory::get();
            $inboxRepo = $container->get(InboundDeliveryRepositoryInterface::class);
            $attemptRepo = $container->get(WebhookAttemptRepositoryInterface::class);
            $receiver = $container->get(InboundWebhookReceiver::class);

            $delivery = $inboxRepo->findById($id);
            if ($delivery === null) {
                $io->error("Inbound delivery not found: {$id}");
                return Command::FAILURE;
            }

            $io->info("Replaying inbound delivery {$id} (endpoint: {$delivery->getEndpointKey()})");

            // Record replay attempt
            $attemptRepo->save(new WebhookAttempt(
                id: Uuid7::generate(),
                direction: WebhookDirection::Inbound,
                inboxId: $delivery->getId(),
                outboxId: null,
                eventType: 'replayed',
                attemptNumber: null,
                statusBefore: $delivery->getStatus()->value,
                statusAfter: $delivery->getStatus()->value,
                workerId: 'cli:webhook:replay:inbound',
                httpStatus: null,
                message: 'Manual replay initiated',
                details: null,
            ));

            // Reconstruct envelope and re-receive
            $envelope = new InboundWebhookEnvelope(
                endpointKey: $delivery->getEndpointKey(),
                httpMethod: $delivery->getHttpMethod(),
                requestUri: $delivery->getRequestUri(),
                headers: $delivery->getHeaders() ?? [],
                rawBody: $delivery->getRawBody() ?? '',
                contentType: $delivery->getContentType(),
            );

            $result = $receiver->receive($envelope);
            $io->success("Replay completed. Status: {$result->getStatus()->value}");
        } catch (\Throwable $e) {
            $io->error("Replay failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
