<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'webhook:show', description: 'Display webhook endpoint, inbox, or outbox details')]
final class WebhookShowCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('webhook:show')
            ->setDescription('Display webhook endpoint, inbox, or outbox details')
            ->addArgument(
                name: 'type',
                mode: InputArgument::REQUIRED,
                description: 'What to show: endpoints, inbox, outbox, or attempts',
            )
            ->addArgument(
                name: 'id',
                mode: InputArgument::OPTIONAL,
                description: 'Specific ID to show details for',
            )
            ->addOption(
                name: 'status',
                shortcut: 's',
                mode: InputOption::VALUE_OPTIONAL,
                description: 'Filter by status',
            )
            ->addOption(
                name: 'limit',
                shortcut: 'l',
                mode: InputOption::VALUE_OPTIONAL,
                description: 'Max rows to display',
                default: '20',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getArgument('type');
        $id = $input->getArgument('id');

        try {
            $container = ContainerFactory::get();

            return match ($type) {
                'endpoints' => $this->showEndpoints($container, $io, $id),
                'inbox' => $this->showInbox($container, $io, $id, $input),
                'outbox' => $this->showOutbox($container, $io, $id, $input),
                'attempts' => $this->showAttempts($container, $io, $id),
                default => $this->invalidType($io, $type),
            };
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showEndpoints(object $container, SymfonyStyle $io, ?string $id): int
    {
        $repo = $container->get(WebhookEndpointDefinitionRepositoryInterface::class);

        if ($id !== null) {
            $endpoint = $repo->findByEndpointKey($id) ?? $repo->findById($id);
            if ($endpoint === null) {
                $io->error("Endpoint not found: {$id}");
                return Command::FAILURE;
            }
            $io->definitionList(
                ['ID' => $endpoint->id],
                ['Endpoint Key' => $endpoint->endpointKey],
                ['Direction' => $endpoint->direction->value],
                ['Provider' => $endpoint->providerKey],
                ['Enabled' => $endpoint->enabled ? 'Yes' : 'No'],
                ['Target URL' => $endpoint->targetUrl ?? '(none)'],
                ['Verification' => $endpoint->verificationMode ?? '(none)'],
                ['Secret Ref' => $endpoint->secretRef !== null ? '***' . substr($endpoint->secretRef, -4) : '(none)'],
                ['Max Attempts' => (string) $endpoint->maxAttempts],
                ['Timeout' => $endpoint->timeoutSeconds . 's'],
            );
            return Command::SUCCESS;
        }

        $endpoints = $repo->findAll();
        if ($endpoints === []) {
            $io->info('No webhook endpoints defined.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($endpoints as $ep) {
            $rows[] = [
                $ep->endpointKey,
                $ep->direction->value,
                $ep->providerKey,
                $ep->enabled ? 'Yes' : 'No',
                $ep->targetUrl ?? '-',
            ];
        }

        $io->table(['Endpoint Key', 'Direction', 'Provider', 'Enabled', 'Target URL'], $rows);
        return Command::SUCCESS;
    }

    private function showInbox(object $container, SymfonyStyle $io, ?string $id, InputInterface $input): int
    {
        $repo = $container->get(InboundDeliveryRepositoryInterface::class);

        if ($id !== null) {
            $delivery = $repo->findById($id);
            if ($delivery === null) {
                $io->error("Inbound delivery not found: {$id}");
                return Command::FAILURE;
            }
            $io->definitionList(
                ['ID' => $delivery->getId()],
                ['Endpoint' => $delivery->getEndpointKey()],
                ['Provider' => $delivery->getProviderKey()],
                ['Status' => $delivery->getStatus()->value],
                ['Signature' => $delivery->getSignatureStatus()->value],
                ['Event Type' => $delivery->getParsedEventType() ?? '(unknown)'],
                ['First Received' => $delivery->getFirstReceivedAt()->format('Y-m-d H:i:s')],
                ['Duplicates' => (string) $delivery->getDuplicateCount()],
                ['Error' => $delivery->getLastError() ?? '(none)'],
            );
            return Command::SUCCESS;
        }

        $status = $input->getOption('status');
        $limit = (int) $input->getOption('limit');

        if ($status !== null) {
            $deliveries = $repo->findByStatus($status, $limit);
        } else {
            $deliveries = $repo->findByStatus('received', $limit);
        }

        if ($deliveries === []) {
            $io->info('No inbox entries found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($deliveries as $d) {
            $rows[] = [
                substr($d->getId(), 0, 8) . '...',
                $d->getEndpointKey(),
                $d->getStatus()->value,
                $d->getParsedEventType() ?? '-',
                $d->getFirstReceivedAt()->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(['ID', 'Endpoint', 'Status', 'Event', 'Received'], $rows);
        return Command::SUCCESS;
    }

    private function showOutbox(object $container, SymfonyStyle $io, ?string $id, InputInterface $input): int
    {
        $repo = $container->get(OutboundDeliveryRepositoryInterface::class);

        if ($id !== null) {
            $delivery = $repo->findById($id);
            if ($delivery === null) {
                $io->error("Outbound delivery not found: {$id}");
                return Command::FAILURE;
            }
            $io->definitionList(
                ['ID' => $delivery->getId()],
                ['Endpoint' => $delivery->getEndpointKey()],
                ['Event Type' => $delivery->getEventType()],
                ['Status' => $delivery->getStatus()->value],
                ['Attempts' => $delivery->getAttemptCount() . '/' . $delivery->getMaxAttempts()],
                ['Next Attempt' => $delivery->getNextAttemptAt()->format('Y-m-d H:i:s')],
                ['Last Response' => $delivery->getLastResponseStatus() !== null ? "HTTP {$delivery->getLastResponseStatus()}" : '(none)'],
                ['Error' => $delivery->getLastError() ?? '(none)'],
            );
            return Command::SUCCESS;
        }

        $status = $input->getOption('status');
        $limit = (int) $input->getOption('limit');

        if ($status !== null) {
            $deliveries = $repo->findByStatus($status, $limit);
        } else {
            $deliveries = $repo->findByStatus('pending', $limit);
        }

        if ($deliveries === []) {
            $io->info('No outbox entries found.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($deliveries as $d) {
            $rows[] = [
                substr($d->getId(), 0, 8) . '...',
                $d->getEndpointKey(),
                $d->getEventType(),
                $d->getStatus()->value,
                $d->getAttemptCount() . '/' . $d->getMaxAttempts(),
            ];
        }

        $io->table(['ID', 'Endpoint', 'Event', 'Status', 'Attempts'], $rows);
        return Command::SUCCESS;
    }

    private function showAttempts(object $container, SymfonyStyle $io, ?string $id): int
    {
        if ($id === null) {
            $io->error('An inbox or outbox ID is required for showing attempts.');
            return Command::FAILURE;
        }

        $repo = $container->get(WebhookAttemptRepositoryInterface::class);

        // Try inbound first, then outbound
        $attempts = $repo->findByInboxId($id);
        if ($attempts === []) {
            $attempts = $repo->findByOutboxId($id);
        }

        if ($attempts === []) {
            $io->info("No attempts found for ID: {$id}");
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($attempts as $a) {
            $rows[] = [
                $a->direction->value,
                $a->eventType,
                $a->statusBefore ?? '-',
                $a->statusAfter ?? '-',
                $a->httpStatus !== null ? (string) $a->httpStatus : '-',
                $a->message ?? '-',
                $a->createdAt->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(['Dir', 'Event', 'Before', 'After', 'HTTP', 'Message', 'At'], $rows);
        return Command::SUCCESS;
    }

    private function invalidType(SymfonyStyle $io, string $type): int
    {
        $io->error("Unknown type: {$type}. Use: endpoints, inbox, outbox, attempts");
        return Command::FAILURE;
    }
}
