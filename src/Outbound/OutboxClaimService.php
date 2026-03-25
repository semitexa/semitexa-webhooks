<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Outbound;

use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;

final class OutboxClaimService
{
    #[InjectAsReadonly]
    protected OutboundDeliveryRepositoryInterface $outboxRepo;

    #[InjectAsReadonly]
    protected WebhookConfig $config;

    public function claim(string $workerId): ?OutboundDelivery
    {
        $leaseExpiresAt = new \DateTimeImmutable("+{$this->config->defaultLeaseSeconds} seconds");

        return $this->outboxRepo->claimAndLease($workerId, $leaseExpiresAt);
    }
}
