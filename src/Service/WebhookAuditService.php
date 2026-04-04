<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Service;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Uuid\Uuid7;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Enum\WebhookDirection;

final class WebhookAuditService
{
    #[InjectAsReadonly]
    protected WebhookAttemptRepositoryInterface $attemptRepo;

    public function recordInboundAttempt(
        string $inboxId,
        string $eventType,
        ?string $statusBefore,
        ?string $statusAfter,
        ?string $workerId = null,
        ?int $httpStatus = null,
        ?string $message = null,
        ?array $details = null,
    ): void {
        $this->attemptRepo->save(new WebhookAttempt(
            id: Uuid7::generate(),
            direction: WebhookDirection::Inbound,
            inboxId: $inboxId,
            outboxId: null,
            eventType: $eventType,
            attemptNumber: null,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            workerId: $workerId,
            httpStatus: $httpStatus,
            message: $message,
            details: $details,
        ));
    }

    public function recordOutboundAttempt(
        string $outboxId,
        string $eventType,
        ?string $statusBefore,
        ?string $statusAfter,
        ?int $attemptNumber = null,
        ?string $workerId = null,
        ?int $httpStatus = null,
        ?string $message = null,
        ?array $details = null,
    ): void {
        $this->attemptRepo->save(new WebhookAttempt(
            id: Uuid7::generate(),
            direction: WebhookDirection::Outbound,
            inboxId: null,
            outboxId: $outboxId,
            eventType: $eventType,
            attemptNumber: $attemptNumber,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            workerId: $workerId,
            httpStatus: $httpStatus,
            message: $message,
            details: $details,
        ));
    }
}
