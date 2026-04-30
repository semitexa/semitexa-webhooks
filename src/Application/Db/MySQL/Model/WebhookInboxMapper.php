<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Enum\InboundStatus;
use Semitexa\Webhooks\Enum\SignatureStatus;

#[AsMapper(resourceModel: WebhookInboxResourceModel::class, domainModel: InboundDelivery::class)]
final class WebhookInboxMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WebhookInboxResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new InboundDelivery(
            id: $resourceModel->id,
            endpointDefinitionId: $resourceModel->endpointDefinitionId,
            providerKey: $resourceModel->providerKey,
            endpointKey: $resourceModel->endpointKey,
            tenantId: $resourceModel->tenantId,
            providerEventId: $resourceModel->providerEventId,
            dedupeKey: $resourceModel->dedupeKey,
            signatureStatus: SignatureStatus::from($resourceModel->signatureStatus),
            status: InboundStatus::from($resourceModel->status),
            contentType: $resourceModel->contentType,
            httpMethod: $resourceModel->httpMethod,
            requestUri: $resourceModel->requestUri,
            headers: $resourceModel->headersJson !== null ? json_decode($resourceModel->headersJson, true, 512, JSON_THROW_ON_ERROR) : null,
            rawBody: $resourceModel->rawBody,
            rawBodySha256: $resourceModel->rawBodySha256,
            parsedEventType: $resourceModel->parsedEventType,
            firstReceivedAt: $resourceModel->firstReceivedAt,
            lastReceivedAt: $resourceModel->lastReceivedAt,
            processingStartedAt: $resourceModel->processingStartedAt,
            processedAt: $resourceModel->processedAt,
            failedAt: $resourceModel->failedAt,
            duplicateCount: $resourceModel->duplicateCount,
            lastError: $resourceModel->lastError,
            metadata: $resourceModel->metadataJson !== null ? json_decode($resourceModel->metadataJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $resourceModel->createdAt ?? new \DateTimeImmutable(),
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof InboundDelivery || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookInboxResourceModel(
            id: $domainModel->getId(),
            endpointDefinitionId: $domainModel->getEndpointDefinitionId(),
            providerKey: $domainModel->getProviderKey(),
            endpointKey: $domainModel->getEndpointKey(),
            tenantId: $domainModel->getTenantId(),
            providerEventId: $domainModel->getProviderEventId(),
            dedupeKey: $domainModel->getDedupeKey(),
            signatureStatus: $domainModel->getSignatureStatus()->value,
            status: $domainModel->getStatus()->value,
            contentType: $domainModel->getContentType(),
            httpMethod: $domainModel->getHttpMethod(),
            requestUri: $domainModel->getRequestUri(),
            headersJson: $domainModel->getHeaders() !== null ? json_encode($domainModel->getHeaders(), JSON_THROW_ON_ERROR) : null,
            rawBody: $domainModel->getRawBody(),
            rawBodySha256: $domainModel->getRawBodySha256(),
            parsedEventType: $domainModel->getParsedEventType(),
            firstReceivedAt: $domainModel->getFirstReceivedAt(),
            lastReceivedAt: $domainModel->getLastReceivedAt(),
            processingStartedAt: $domainModel->getProcessingStartedAt(),
            processedAt: $domainModel->getProcessedAt(),
            failedAt: $domainModel->getFailedAt(),
            duplicateCount: $domainModel->getDuplicateCount(),
            lastError: $domainModel->getLastError(),
            metadataJson: $domainModel->getMetadata() !== null ? json_encode($domainModel->getMetadata(), JSON_THROW_ON_ERROR) : null,
            createdAt: $domainModel->getCreatedAt(),
            updatedAt: new \DateTimeImmutable(),
        );
    }
}
