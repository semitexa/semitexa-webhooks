<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Enum\OutboundStatus;

final class OutboundDelivery
{
    private OutboundStatus $status;
    private \DateTimeImmutable $nextAttemptAt;
    private ?\DateTimeImmutable $lastAttemptAt;
    private ?\DateTimeImmutable $deliveredAt;
    private int $attemptCount;
    private ?string $leaseOwner;
    private ?\DateTimeImmutable $leaseExpiresAt;
    private ?int $lastResponseStatus;
    private ?string $lastResponseHeadersJson;
    private ?string $lastResponseBody;
    private ?string $lastError;

    public function __construct(
        private readonly string $id,
        private readonly string $endpointDefinitionId,
        private readonly string $endpointKey,
        private readonly string $providerKey,
        private readonly ?string $tenantId,
        private readonly string $eventType,
        OutboundStatus $status,
        private readonly ?string $idempotencyKey,
        private readonly string $payloadJson,
        private readonly ?string $headersJson,
        private readonly ?string $signedHeadersJson,
        \DateTimeImmutable $nextAttemptAt,
        ?\DateTimeImmutable $lastAttemptAt = null,
        ?\DateTimeImmutable $deliveredAt = null,
        int $attemptCount = 0,
        private readonly int $maxAttempts = 5,
        private readonly int $initialBackoffSeconds = 30,
        private readonly int $maxBackoffSeconds = 3600,
        ?string $leaseOwner = null,
        ?\DateTimeImmutable $leaseExpiresAt = null,
        ?int $lastResponseStatus = null,
        ?string $lastResponseHeadersJson = null,
        ?string $lastResponseBody = null,
        ?string $lastError = null,
        private readonly ?string $sourceRef = null,
        private readonly ?array $metadata = null,
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->status = $status;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->lastAttemptAt = $lastAttemptAt;
        $this->deliveredAt = $deliveredAt;
        $this->attemptCount = $attemptCount;
        $this->leaseOwner = $leaseOwner;
        $this->leaseExpiresAt = $leaseExpiresAt;
        $this->lastResponseStatus = $lastResponseStatus;
        $this->lastResponseHeadersJson = $lastResponseHeadersJson;
        $this->lastResponseBody = $lastResponseBody;
        $this->lastError = $lastError;
    }

    public function getId(): string { return $this->id; }
    public function getEndpointDefinitionId(): string { return $this->endpointDefinitionId; }
    public function getEndpointKey(): string { return $this->endpointKey; }
    public function getProviderKey(): string { return $this->providerKey; }
    public function getTenantId(): ?string { return $this->tenantId; }
    public function getEventType(): string { return $this->eventType; }
    public function getStatus(): OutboundStatus { return $this->status; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getPayloadJson(): string { return $this->payloadJson; }
    public function getHeadersJson(): ?string { return $this->headersJson; }
    public function getSignedHeadersJson(): ?string { return $this->signedHeadersJson; }
    public function getNextAttemptAt(): \DateTimeImmutable { return $this->nextAttemptAt; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function getDeliveredAt(): ?\DateTimeImmutable { return $this->deliveredAt; }
    public function getAttemptCount(): int { return $this->attemptCount; }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function getInitialBackoffSeconds(): int { return $this->initialBackoffSeconds; }
    public function getMaxBackoffSeconds(): int { return $this->maxBackoffSeconds; }
    public function getLeaseOwner(): ?string { return $this->leaseOwner; }
    public function getLeaseExpiresAt(): ?\DateTimeImmutable { return $this->leaseExpiresAt; }
    public function getLastResponseStatus(): ?int { return $this->lastResponseStatus; }
    public function getLastResponseHeadersJson(): ?string { return $this->lastResponseHeadersJson; }
    public function getLastResponseBody(): ?string { return $this->lastResponseBody; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markDelivering(string $workerId, \DateTimeImmutable $leaseExpiresAt): void
    {
        $this->status = OutboundStatus::Delivering;
        $this->leaseOwner = $workerId;
        $this->leaseExpiresAt = $leaseExpiresAt;
        $this->lastAttemptAt = new \DateTimeImmutable();
        $this->attemptCount++;
    }

    public function markDelivered(int $httpStatus, ?string $responseHeaders, ?string $responseBody): void
    {
        $this->status = OutboundStatus::Delivered;
        $this->deliveredAt = new \DateTimeImmutable();
        $this->lastResponseStatus = $httpStatus;
        $this->lastResponseHeadersJson = $responseHeaders;
        $this->lastResponseBody = $responseBody;
        $this->leaseOwner = null;
        $this->leaseExpiresAt = null;
        $this->lastError = null;
    }

    public function markRetryScheduled(\DateTimeImmutable $nextAttemptAt, ?string $error): void
    {
        $this->status = OutboundStatus::RetryScheduled;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->leaseOwner = null;
        $this->leaseExpiresAt = null;
        $this->lastError = $error;
    }

    public function markFailed(?int $httpStatus, ?string $responseBody, ?string $error): void
    {
        $this->status = OutboundStatus::Failed;
        $this->lastResponseStatus = $httpStatus;
        $this->lastResponseBody = $responseBody;
        $this->lastError = $error;
        $this->leaseOwner = null;
        $this->leaseExpiresAt = null;
    }

    public function markCancelled(): void
    {
        $this->status = OutboundStatus::Cancelled;
        $this->leaseOwner = null;
        $this->leaseExpiresAt = null;
    }

    public function resetToPending(): void
    {
        $this->status = OutboundStatus::Pending;
        $this->nextAttemptAt = new \DateTimeImmutable();
        $this->attemptCount = 0;
        $this->leaseOwner = null;
        $this->leaseExpiresAt = null;
        $this->lastError = null;
    }

    public function hasAttemptsRemaining(): bool
    {
        return $this->attemptCount < $this->maxAttempts;
    }
}
