<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Service;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;

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

    public function purge(): array
    {
        $cutoff = new \DateTimeImmutable("-{$this->config->retentionDays} days");

        $inboxDeleted = $this->inboxRepo->deleteOlderThan($cutoff);
        $outboxDeleted = $this->outboxRepo->deleteOlderThan($cutoff);
        $attemptsDeleted = $this->attemptRepo->deleteOlderThan($cutoff);

        return [
            'inbox' => $inboxDeleted,
            'outbox' => $outboxDeleted,
            'attempts' => $attemptsDeleted,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
        ];
    }
}
