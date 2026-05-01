<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Domain\Enum\WebhookDirection;

final readonly class WebhookAttempt
{
    public function __construct(
        public string $id,
        public WebhookDirection $direction,
        public ?string $inboxId,
        public ?string $outboxId,
        public string $eventType,
        public ?int $attemptNumber,
        public ?string $statusBefore,
        public ?string $statusAfter,
        public ?string $workerId,
        public ?int $httpStatus,
        public ?string $message,
        public ?array $details,
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}
}
