<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookTransportInterface;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\DeliveryAttemptOutcome;
use Semitexa\Webhooks\Domain\Model\TransportResult;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Processes pending outbound webhook deliveries: claim → mark-delivering →
 * transport → classify result → save → record attempt.
 *
 * Two entry points:
 *   {@see processOne()} — drains one due delivery (or returns idle if none).
 *                         Pure, no sleep — driven by tests AND by run() in
 *                         production.
 *   {@see run()}        — long-running poll loop wrapping processOne(),
 *                         sleeps when no delivery is due.
 *
 * Retry classification:
 *   2xx                 → markDelivered
 *   4xx                 → markFailed (PERMANENT — no retry)
 *   5xx                 → markRetryScheduled if attempts remain, else markFailed
 *   transport exception → markRetryScheduled if attempts remain, else markFailed
 *   null status         → retryable (transport-level failure with no HTTP code)
 *
 * Attempt count is incremented EXACTLY ONCE per call to processOne(), inside
 * markDelivering(). Bypassing markDelivering() would leave attemptCount at
 * zero forever, causing the worker to retry an unhealthy delivery indefinitely.
 */
final class WebhookDeliveryWorker
{
    private ?OutputInterface $output = null;
    private bool $running = true;

    #[InjectAsReadonly]
    protected OutboxClaimService $claimService;

    #[InjectAsReadonly]
    protected WebhookTransportInterface $transport;

    #[InjectAsReadonly]
    protected OutboundDeliveryRepositoryInterface $outboxRepo;

    #[InjectAsReadonly]
    protected WebhookAttemptRepositoryInterface $attemptRepo;

    #[InjectAsReadonly]
    protected BackoffCalculator $backoffCalculator;

    public function setOutput(?OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Process exactly one due delivery and return what happened.
     */
    public function processOne(string $workerId, ?\DateTimeImmutable $leaseExpiresAt = null): DeliveryAttemptOutcome
    {
        $delivery = $this->claimService->claim($workerId);
        if ($delivery === null) {
            return DeliveryAttemptOutcome::idle();
        }

        // The repository's claimAndLease atomically flipped status to
        // Delivering, set lease_owner = $workerId, set lease_expires_at, and
        // incremented attempt_count in a single SQL statement. The returned
        // delivery already reflects that state — no markDelivering() call
        // here would only mutate the in-memory object and risk drifting
        // from the database.

        $statusBefore = OutboundStatus::Delivering->value;

        try {
            $result = $this->transport->send($delivery);
        } catch (\Throwable $e) {
            // Transport exceptions are retryable — same class as 5xx.
            $result = TransportResult::failure(
                httpStatus: null,
                errorMessage: $e::class . ': ' . $e->getMessage(),
            );
        }

        if ($result->success) {
            $finalized = $this->outboxRepo->markDeliveredIfOwned(
                $delivery->getId(),
                $workerId,
                $result->httpStatus ?? 200,
                $result->responseHeaders,
                $result->responseBody,
            );
            if (!$finalized) {
                return $this->lostLeaseOutcome($delivery, $result, 'delivered-but-lost-lease');
            }
            $delivery->markDelivered(
                $result->httpStatus ?? 200,
                $result->responseHeaders,
                $result->responseBody,
            );
            $this->recordAttempt(
                $delivery->getId(),
                'delivery_attempt',
                $statusBefore,
                $delivery->getStatus()->value,
                $delivery->getAttemptCount(),
                $workerId,
                $result->httpStatus,
                'Delivered successfully',
            );
            $this->log("Delivery {$delivery->getId()} succeeded (HTTP {$result->httpStatus})");

            return new DeliveryAttemptOutcome(
                idleNoDeliveryDue: false,
                deliveryId: $delivery->getId(),
                newStatus: $delivery->getStatus(),
                transportResult: $result,
                attemptNumber: $delivery->getAttemptCount(),
                reason: 'delivered',
            );
        }

        $isPermanent = $result->httpStatus !== null
            && $result->httpStatus >= 400
            && $result->httpStatus < 500;

        if ($isPermanent || !$delivery->hasAttemptsRemaining()) {
            $finalized = $this->outboxRepo->markFailedIfOwned(
                $delivery->getId(),
                $workerId,
                $result->httpStatus,
                $result->responseBody,
                $result->errorMessage,
            );
            if (!$finalized) {
                return $this->lostLeaseOutcome($delivery, $result, 'failed-but-lost-lease');
            }
            $delivery->markFailed($result->httpStatus, $result->responseBody, $result->errorMessage);
            $this->recordAttempt(
                $delivery->getId(),
                $isPermanent ? 'failed_permanent_4xx' : 'failed_attempts_exhausted',
                $statusBefore,
                $delivery->getStatus()->value,
                $delivery->getAttemptCount(),
                $workerId,
                $result->httpStatus,
                $result->errorMessage,
            );
            $reason = $isPermanent
                ? '4xx permanent failure (no retry)'
                : "attempts exhausted ({$delivery->getAttemptCount()}/{$delivery->getMaxAttempts()})";
            $this->log("Delivery {$delivery->getId()} failed: {$reason}");

            return new DeliveryAttemptOutcome(
                idleNoDeliveryDue: false,
                deliveryId: $delivery->getId(),
                newStatus: $delivery->getStatus(),
                transportResult: $result,
                attemptNumber: $delivery->getAttemptCount(),
                reason: $reason,
            );
        }

        // Retryable failure with attempts remaining.
        $nextAttemptAt = $this->backoffCalculator->nextAttemptAt(
            $delivery->getAttemptCount(),
            $delivery->getInitialBackoffSeconds(),
            $delivery->getMaxBackoffSeconds(),
        );
        $finalized = $this->outboxRepo->markRetryScheduledIfOwned(
            $delivery->getId(),
            $workerId,
            $nextAttemptAt,
            $result->errorMessage,
        );
        if (!$finalized) {
            return $this->lostLeaseOutcome($delivery, $result, 'retry-but-lost-lease');
        }
        $delivery->markRetryScheduled($nextAttemptAt, $result->errorMessage);
        $this->recordAttempt(
            $delivery->getId(),
            'retry_scheduled',
            $statusBefore,
            $delivery->getStatus()->value,
            $delivery->getAttemptCount(),
            $workerId,
            $result->httpStatus,
            $result->errorMessage,
        );
        $this->log("Delivery {$delivery->getId()} retry scheduled (attempt {$delivery->getAttemptCount()}/{$delivery->getMaxAttempts()})");

        return new DeliveryAttemptOutcome(
            idleNoDeliveryDue: false,
            deliveryId: $delivery->getId(),
            newStatus: $delivery->getStatus(),
            transportResult: $result,
            attemptNumber: $delivery->getAttemptCount(),
            reason: 'retry-scheduled',
        );
    }

    /**
     * The CAS finalization rejected our update because another worker has
     * taken over (the lease expired and this worker raced beyond the
     * deadline). We cannot safely log a final attempt — the row is no
     * longer ours. The transport already sent the request; the receiver
     * is responsible for replay protection. We return an outcome that
     * marks the lost lease so callers can act on it (or just keep
     * polling), and we DO NOT touch the row again.
     */
    private function lostLeaseOutcome(
        \Semitexa\Webhooks\Domain\Model\OutboundDelivery $delivery,
        TransportResult $result,
        string $reason,
    ): DeliveryAttemptOutcome {
        $this->log(
            "Delivery {$delivery->getId()}: lost lease before finalization ({$reason})",
            'warning',
        );
        return new DeliveryAttemptOutcome(
            idleNoDeliveryDue: false,
            deliveryId: $delivery->getId(),
            newStatus: $delivery->getStatus(),
            transportResult: $result,
            attemptNumber: $delivery->getAttemptCount(),
            reason: $reason,
        );
    }

    public function run(string $workerId, int $pollIntervalSeconds = 5): void
    {
        $this->log("Webhook delivery worker started (worker={$workerId})");

        while ($this->running) {
            $outcome = $this->processOne($workerId);
            if ($outcome->idleNoDeliveryDue) {
                sleep($pollIntervalSeconds);
            }
        }

        $this->log("Webhook delivery worker stopped");
    }

    private function recordAttempt(
        string $outboxId,
        string $eventType,
        string $statusBefore,
        string $statusAfter,
        int $attemptNumber,
        string $workerId,
        ?int $httpStatus,
        ?string $message,
    ): void {
        $attempt = new WebhookAttempt(
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
            details: null,
        );
        $this->attemptRepo->save($attempt);
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->output !== null) {
            $tag = match ($level) {
                'error' => 'error',
                'warning' => 'comment',
                'success' => 'info',
                default => 'info',
            };
            $this->output->writeln("<{$tag}>{$message}</{$tag}>");
        }
    }
}
