<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Enum\InboundStatus;
use Semitexa\Webhooks\Enum\SignatureStatus;

final class InboundDelivery
{
    private InboundStatus $status;
    private SignatureStatus $signatureStatus;
    private ?\DateTimeImmutable $processingStartedAt;
    private ?\DateTimeImmutable $processedAt;
    private ?\DateTimeImmutable $failedAt;
    private \DateTimeImmutable $lastReceivedAt;
    private int $duplicateCount;
    private ?string $lastError;

    public function __construct(
        private readonly string $id,
        private readonly string $endpointDefinitionId,
        private readonly string $providerKey,
        private readonly string $endpointKey,
        private readonly ?string $tenantId,
        private readonly ?string $providerEventId,
        private readonly string $dedupeKey,
        SignatureStatus $signatureStatus,
        InboundStatus $status,
        private readonly ?string $contentType,
        private readonly string $httpMethod,
        private readonly string $requestUri,
        private readonly ?array $headers,
        private readonly ?string $rawBody,
        private readonly string $rawBodySha256,
        private readonly ?string $parsedEventType,
        private readonly \DateTimeImmutable $firstReceivedAt,
        \DateTimeImmutable $lastReceivedAt,
        ?\DateTimeImmutable $processingStartedAt = null,
        ?\DateTimeImmutable $processedAt = null,
        ?\DateTimeImmutable $failedAt = null,
        int $duplicateCount = 0,
        ?string $lastError = null,
        private readonly ?array $metadata = null,
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->status = $status;
        $this->signatureStatus = $signatureStatus;
        $this->lastReceivedAt = $lastReceivedAt;
        $this->processingStartedAt = $processingStartedAt;
        $this->processedAt = $processedAt;
        $this->failedAt = $failedAt;
        $this->duplicateCount = $duplicateCount;
        $this->lastError = $lastError;
    }

    public function getId(): string { return $this->id; }
    public function getEndpointDefinitionId(): string { return $this->endpointDefinitionId; }
    public function getProviderKey(): string { return $this->providerKey; }
    public function getEndpointKey(): string { return $this->endpointKey; }
    public function getTenantId(): ?string { return $this->tenantId; }
    public function getProviderEventId(): ?string { return $this->providerEventId; }
    public function getDedupeKey(): string { return $this->dedupeKey; }
    public function getSignatureStatus(): SignatureStatus { return $this->signatureStatus; }
    public function getStatus(): InboundStatus { return $this->status; }
    public function getContentType(): ?string { return $this->contentType; }
    public function getHttpMethod(): string { return $this->httpMethod; }
    public function getRequestUri(): string { return $this->requestUri; }
    public function getHeaders(): ?array { return $this->headers; }
    public function getRawBody(): ?string { return $this->rawBody; }
    public function getRawBodySha256(): string { return $this->rawBodySha256; }
    public function getParsedEventType(): ?string { return $this->parsedEventType; }
    public function getFirstReceivedAt(): \DateTimeImmutable { return $this->firstReceivedAt; }
    public function getLastReceivedAt(): \DateTimeImmutable { return $this->lastReceivedAt; }
    public function getProcessingStartedAt(): ?\DateTimeImmutable { return $this->processingStartedAt; }
    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
    public function getFailedAt(): ?\DateTimeImmutable { return $this->failedAt; }
    public function getDuplicateCount(): int { return $this->duplicateCount; }
    public function getLastError(): ?string { return $this->lastError; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markVerified(): void
    {
        $this->signatureStatus = SignatureStatus::Verified;
        $this->status = InboundStatus::Verified;
    }

    public function markRejectedSignature(): void
    {
        $this->signatureStatus = SignatureStatus::Rejected;
        $this->status = InboundStatus::RejectedSignature;
    }

    public function markProcessing(): void
    {
        $this->status = InboundStatus::Processing;
        $this->processingStartedAt = new \DateTimeImmutable();
    }

    public function markProcessed(): void
    {
        $this->status = InboundStatus::Processed;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $error): void
    {
        $this->status = InboundStatus::Failed;
        $this->failedAt = new \DateTimeImmutable();
        $this->lastError = $error;
    }

    public function markDuplicateIgnored(): void
    {
        $this->status = InboundStatus::DuplicateIgnored;
        $this->duplicateCount++;
        $this->lastReceivedAt = new \DateTimeImmutable();
    }
}
