<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Orm\Application\Service\Uuid7;

final class InboundWebhookService
{
    #[InjectAsReadonly]
    protected InboundDeliveryRepositoryInterface $inboundRepo;

    #[InjectAsReadonly]
    protected WebhookAttemptRepositoryInterface $attemptRepo;

    public function markVerified(InboundDelivery $delivery): void
    {
        $before = $delivery->getStatus()->value;
        $delivery->markVerified();
        $this->inboundRepo->save($delivery);
        $this->recordAttempt($delivery, 'verified', $before, $delivery->getStatus()->value);
    }

    public function markRejectedSignature(InboundDelivery $delivery, string $reason): void
    {
        $before = $delivery->getStatus()->value;
        $delivery->markRejectedSignature();
        $this->inboundRepo->save($delivery);
        $this->recordAttempt($delivery, 'rejected_signature', $before, $delivery->getStatus()->value, $reason);
    }

    public function markProcessing(InboundDelivery $delivery): void
    {
        $before = $delivery->getStatus()->value;
        $delivery->markProcessing();
        $this->inboundRepo->save($delivery);
        $this->recordAttempt($delivery, 'processing_started', $before, $delivery->getStatus()->value);
    }

    public function markProcessed(InboundDelivery $delivery): void
    {
        $before = $delivery->getStatus()->value;
        $delivery->markProcessed();
        $this->inboundRepo->save($delivery);
        $this->recordAttempt($delivery, 'processed', $before, $delivery->getStatus()->value);
    }

    public function markFailed(InboundDelivery $delivery, string $error): void
    {
        $before = $delivery->getStatus()->value;
        $delivery->markFailed($error);
        $this->inboundRepo->save($delivery);
        $this->recordAttempt($delivery, 'failed', $before, $delivery->getStatus()->value, $error);
    }

    private function recordAttempt(
        InboundDelivery $delivery,
        string $eventType,
        string $statusBefore,
        string $statusAfter,
        ?string $message = null,
    ): void {
        $attempt = new WebhookAttempt(
            id: Uuid7::generate(),
            direction: WebhookDirection::Inbound,
            inboxId: $delivery->getId(),
            outboxId: null,
            eventType: $eventType,
            attemptNumber: null,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            workerId: null,
            httpStatus: null,
            message: $message,
            details: null,
        );
        $this->attemptRepo->save($attempt);
    }
}
