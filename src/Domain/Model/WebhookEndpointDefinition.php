<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Domain\Enum\WebhookDirection;

final readonly class WebhookEndpointDefinition
{
    public function __construct(
        private string $id,
        private string $endpointKey,
        private WebhookDirection $direction,
        private string $providerKey,
        private bool $enabled,
        private ?string $tenantId,
        private ?string $verificationMode,
        private ?string $signingMode,
        private ?string $secretRef,
        private ?string $targetUrl,
        private int $timeoutSeconds,
        private int $maxAttempts,
        private int $initialBackoffSeconds,
        private int $maxBackoffSeconds,
        private ?int $dedupeWindowSeconds,
        private ?string $handlerClass,
        private ?array $defaultHeaders,
        private ?array $metadata,
        private \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getEndpointKey(): string
    {
        return $this->endpointKey;
    }

    public function getDirection(): WebhookDirection
    {
        return $this->direction;
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getVerificationMode(): ?string
    {
        return $this->verificationMode;
    }

    public function getSigningMode(): ?string
    {
        return $this->signingMode;
    }

    public function getSecretRef(): ?string
    {
        return $this->secretRef;
    }

    public function getTargetUrl(): ?string
    {
        return $this->targetUrl;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getInitialBackoffSeconds(): int
    {
        return $this->initialBackoffSeconds;
    }

    public function getMaxBackoffSeconds(): int
    {
        return $this->maxBackoffSeconds;
    }

    public function getDedupeWindowSeconds(): ?int
    {
        return $this->dedupeWindowSeconds;
    }

    public function getHandlerClass(): ?string
    {
        return $this->handlerClass;
    }

    public function getDefaultHeaders(): ?array
    {
        return $this->defaultHeaders;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
