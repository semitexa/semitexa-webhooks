<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

final readonly class OutboundWebhookMessage
{
    public function __construct(
        public string $endpointKey,
        public string $eventType,
        public array $payload,
        public array $headers = [],
        public ?string $idempotencyKey = null,
        public ?string $sourceRef = null,
    ) {}
}
