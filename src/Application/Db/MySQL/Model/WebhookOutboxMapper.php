<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Enum\OutboundStatus;

#[AsMapper(resourceModel: WebhookOutboxResourceModel::class, domainModel: OutboundDelivery::class)]
final class WebhookOutboxMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WebhookOutboxResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new OutboundDelivery(
            id: $resourceModel->id,
            endpointDefinitionId: $resourceModel->endpointDefinitionId,
            endpointKey: $resourceModel->endpointKey,
            providerKey: $resourceModel->providerKey,
            tenantId: $resourceModel->tenantId,
            eventType: $resourceModel->eventType,
            status: OutboundStatus::from($resourceModel->status),
            idempotencyKey: $resourceModel->idempotencyKey,
            payloadJson: $resourceModel->payloadJson,
            headersJson: $resourceModel->headersJson,
            signedHeadersJson: $resourceModel->signedHeadersJson,
            nextAttemptAt: $resourceModel->nextAttemptAt,
            lastAttemptAt: $resourceModel->lastAttemptAt,
            deliveredAt: $resourceModel->deliveredAt,
            attemptCount: $resourceModel->attemptCount,
            maxAttempts: $resourceModel->maxAttempts,
            initialBackoffSeconds: $resourceModel->initialBackoffSeconds,
            maxBackoffSeconds: $resourceModel->maxBackoffSeconds,
            leaseOwner: $resourceModel->leaseOwner,
            leaseExpiresAt: $resourceModel->leaseExpiresAt,
            lastResponseStatus: $resourceModel->lastResponseStatus,
            lastResponseHeadersJson: $resourceModel->lastResponseHeadersJson,
            lastResponseBody: $resourceModel->lastResponseBody,
            lastError: $resourceModel->lastError,
            sourceRef: $resourceModel->sourceRef,
            metadata: $resourceModel->metadataJson !== null ? json_decode($resourceModel->metadataJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $resourceModel->createdAt ?? new \DateTimeImmutable(),
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof OutboundDelivery || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookOutboxResourceModel(
            id: $domainModel->getId(),
            endpointDefinitionId: $domainModel->getEndpointDefinitionId(),
            endpointKey: $domainModel->getEndpointKey(),
            providerKey: $domainModel->getProviderKey(),
            tenantId: $domainModel->getTenantId(),
            eventType: $domainModel->getEventType(),
            status: $domainModel->getStatus()->value,
            idempotencyKey: $domainModel->getIdempotencyKey(),
            payloadJson: $domainModel->getPayloadJson(),
            headersJson: $domainModel->getHeadersJson(),
            signedHeadersJson: $domainModel->getSignedHeadersJson(),
            nextAttemptAt: $domainModel->getNextAttemptAt(),
            lastAttemptAt: $domainModel->getLastAttemptAt(),
            deliveredAt: $domainModel->getDeliveredAt(),
            attemptCount: $domainModel->getAttemptCount(),
            maxAttempts: $domainModel->getMaxAttempts(),
            initialBackoffSeconds: $domainModel->getInitialBackoffSeconds(),
            maxBackoffSeconds: $domainModel->getMaxBackoffSeconds(),
            leaseOwner: $domainModel->getLeaseOwner(),
            leaseExpiresAt: $domainModel->getLeaseExpiresAt(),
            lastResponseStatus: $domainModel->getLastResponseStatus(),
            lastResponseHeadersJson: $domainModel->getLastResponseHeadersJson(),
            lastResponseBody: $domainModel->getLastResponseBody(),
            lastError: $domainModel->getLastError(),
            sourceRef: $domainModel->getSourceRef(),
            metadataJson: $domainModel->getMetadata() !== null ? json_encode($domainModel->getMetadata(), JSON_THROW_ON_ERROR) : null,
            createdAt: $domainModel->getCreatedAt(),
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
