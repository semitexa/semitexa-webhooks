<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

final readonly class DeliveryLease
{
    public function __construct(
        public string $outboxId,
        public string $workerId,
        public \DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
