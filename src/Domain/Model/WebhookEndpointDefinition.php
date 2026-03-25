<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Enum\WebhookDirection;

final readonly class WebhookEndpointDefinition
{
    public function __construct(
        public string $id,
        public string $endpointKey,
        public WebhookDirection $direction,
        public string $providerKey,
        public bool $enabled,
        public ?string $tenantId,
        public ?string $verificationMode,
        public ?string $signingMode,
        public ?string $secretRef,
        public ?string $targetUrl,
        public int $timeoutSeconds,
        public int $maxAttempts,
        public int $initialBackoffSeconds,
        public int $maxBackoffSeconds,
        public ?int $dedupeWindowSeconds,
        public ?string $handlerClass,
        public ?array $defaultHeaders,
        public ?array $metadata,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
