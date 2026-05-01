<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookTransportInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Symfony\Component\Console\Output\OutputInterface;

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

    public function run(string $workerId, int $pollIntervalSeconds = 5): void
    {
        $this->log("Webhook delivery worker started (worker={$workerId})");

        while ($this->running) {
            $delivery = $this->claimService->claim($workerId);

            if ($delivery === null) {
                sleep($pollIntervalSeconds);
                continue;
            }

            $this->log("Claimed delivery {$delivery->getId()} for endpoint {$delivery->getEndpointKey()}");

            $statusBefore = $delivery->getStatus()->value;
            $result = $this->transport->send($delivery);

            if ($result->success) {
                $delivery->markDelivered(
                    $result->httpStatus ?? 200,
                    $result->responseHeaders,
                    $result->responseBody,
                );
                $this->outboxRepo->save($delivery);
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
                continue;
            }

            // Failed attempt
            if ($delivery->hasAttemptsRemaining()) {
                $nextAttemptAt = $this->backoffCalculator->nextAttemptAt(
                    $delivery->getAttemptCount(),
                    $delivery->getInitialBackoffSeconds(),
                    $delivery->getMaxBackoffSeconds(),
                );
                $delivery->markRetryScheduled($nextAttemptAt, $result->errorMessage);
                $this->outboxRepo->save($delivery);
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
            } else {
                $delivery->markFailed($result->httpStatus, $result->responseBody, $result->errorMessage);
                $this->outboxRepo->save($delivery);
                $this->recordAttempt(
                    $delivery->getId(),
                    'failed',
                    $statusBefore,
                    $delivery->getStatus()->value,
                    $delivery->getAttemptCount(),
                    $workerId,
                    $result->httpStatus,
                    $result->errorMessage,
                );
                $this->log("Delivery {$delivery->getId()} failed permanently after {$delivery->getAttemptCount()} attempts");
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
