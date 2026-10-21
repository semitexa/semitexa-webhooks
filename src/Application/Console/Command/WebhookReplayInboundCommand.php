<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookReceiver;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookInboundProcessorInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:replay:inbound', description: 'Replay an inbound webhook delivery by ID')]
final class WebhookReplayInboundCommand extends Command
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
            ->setName('webhook:replay:inbound')
            ->setDescription('Replay an inbound webhook delivery by ID')
            ->addArgument(
                name: 'id',
                mode: InputArgument::REQUIRED,
                description: 'UUID of the inbound delivery to replay',
            )
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also replay a delivery that was already processed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        try {
            $container = $this->container;
            $inboxRepo = $container->get(InboundDeliveryRepositoryInterface::class);
            $attemptRepo = $container->get(WebhookAttemptRepositoryInterface::class);
            $receiver = $container->get(InboundWebhookReceiver::class);

            $delivery = $inboxRepo->findById($id);
            if ($delivery === null) {
                $io->error("Inbound delivery not found: {$id}");
                return Command::FAILURE;
            }

            // Not received again: that re-checked a signature whose timestamp
            // had long expired, and ran no processor. The stored delivery goes
            // to the processor its endpoint names.
            $endpoint = $container->get(WebhookEndpointDefinitionRepositoryInterface::class)->findById($delivery->getEndpointDefinitionId());
            if ($endpoint === null) {
                $io->error("The endpoint of delivery {$id} ({$delivery->getEndpointKey()}) no longer exists.");
                return Command::FAILURE;
            }
            if (!$endpoint->isEnabled()) {
                $io->error("Endpoint {$endpoint->getEndpointKey()} is disabled: receive() would refuse this delivery, and so does replay.");
                return Command::FAILURE;
            }
            $handlerClass = $endpoint->getHandlerClass();
            if ($handlerClass === null || $handlerClass === '') {
                $io->error("Endpoint {$endpoint->getEndpointKey()} names no handler_class: there is nothing to replay the delivery through.");
                return Command::FAILURE;
            }
            $processor = $container->get($handlerClass);
            if (!$processor instanceof WebhookInboundProcessorInterface) {
                $io->error("{$handlerClass} is not a WebhookInboundProcessorInterface.");
                return Command::FAILURE;
            }
            // A processor scoped to the delivery's tenant would run in no
            // tenant at all from a plain CLI call.
            $tenant = $delivery->getTenantId();
            if ($tenant !== null && $tenant !== $this->currentTenant()) {
                $io->error("Delivery {$id} belongs to tenant {$tenant}: run bin/semitexa tenant:run {$tenant} webhook:replay:inbound {$id}");
                return Command::FAILURE;
            }

            $statusBefore = $delivery->getStatus()->value;
            $io->info("Replaying inbound delivery {$id} (endpoint: {$delivery->getEndpointKey()})");
            $result = $receiver->replay($delivery, $processor, (bool) $input->getOption('force'));

            $attemptRepo->save(new WebhookAttempt(
                id: Uuid7::generate(),
                direction: WebhookDirection::Inbound,
                inboxId: $delivery->getId(),
                outboxId: null,
                eventType: 'replayed',
                attemptNumber: null,
                statusBefore: $statusBefore,
                statusAfter: $result->getStatus()->value,
                workerId: 'cli:webhook:replay:inbound',
                httpStatus: null,
                message: 'Manual replay',
                details: null,
            ));

            if ($result->getStatus() === InboundStatus::Failed) {
                $io->error("Replay ran and the processor failed: {$result->getLastError()}");
                return Command::FAILURE;
            }
            $io->success("Replay completed. Status: {$result->getStatus()->value}");
        } catch (\Throwable $e) {
            $io->error("Replay failed: {$e->getMessage()}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** The tenant this process runs in, "default" on a single-tenant install or without tenant:run. */
    private function currentTenant(): string
    {
        $store = $this->container->has(TenantContextStoreInterface::class) ? $this->container->get(TenantContextStoreInterface::class) : null;

        return TenantContextAccess::tenantIdOrDefault($store instanceof TenantContextStoreInterface ? $store->tryGet() : null);
    }
}
