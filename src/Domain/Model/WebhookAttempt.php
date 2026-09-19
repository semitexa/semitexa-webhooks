<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Domain\Enum\WebhookDirection;

final readonly class WebhookAttempt
{
    public function __construct(
        private string $id,
        private WebhookDirection $direction,
        private ?string $inboxId,
        private ?string $outboxId,
        private string $eventType,
        private ?int $attemptNumber,
        private ?string $statusBefore,
        private ?string $statusAfter,
        private ?string $workerId,
        private ?int $httpStatus,
        private ?string $message,
        private ?array $details,
        private \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getDirection(): WebhookDirection
    {
        return $this->direction;
    }

    public function getInboxId(): ?string
    {
        return $this->inboxId;
    }

    public function getOutboxId(): ?string
    {
        return $this->outboxId;
    }

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function getAttemptNumber(): ?int
    {
        return $this->attemptNumber;
    }

    public function getStatusBefore(): ?string
    {
        return $this->statusBefore;
    }

    public function getStatusAfter(): ?string
    {
        return $this->statusAfter;
    }

    public function getWorkerId(): ?string
    {
        return $this->workerId;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
