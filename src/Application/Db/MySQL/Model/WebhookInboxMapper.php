<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Enum\InboundStatus;
use Semitexa\Webhooks\Enum\SignatureStatus;

#[AsMapper(resourceModel: WebhookInboxTableModel::class, domainModel: InboundDelivery::class)]
final class WebhookInboxMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof WebhookInboxTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new InboundDelivery(
            id: $tableModel->id,
            endpointDefinitionId: $tableModel->endpointDefinitionId,
            providerKey: $tableModel->providerKey,
            endpointKey: $tableModel->endpointKey,
            tenantId: $tableModel->tenantId,
            providerEventId: $tableModel->providerEventId,
            dedupeKey: $tableModel->dedupeKey,
            signatureStatus: SignatureStatus::from($tableModel->signatureStatus),
            status: InboundStatus::from($tableModel->status),
            contentType: $tableModel->contentType,
            httpMethod: $tableModel->httpMethod,
            requestUri: $tableModel->requestUri,
            headers: $tableModel->headersJson !== null ? json_decode($tableModel->headersJson, true, 512, JSON_THROW_ON_ERROR) : null,
            rawBody: $tableModel->rawBody,
            rawBodySha256: $tableModel->rawBodySha256,
            parsedEventType: $tableModel->parsedEventType,
            firstReceivedAt: $tableModel->firstReceivedAt,
            lastReceivedAt: $tableModel->lastReceivedAt,
            processingStartedAt: $tableModel->processingStartedAt,
            processedAt: $tableModel->processedAt,
            failedAt: $tableModel->failedAt,
            duplicateCount: $tableModel->duplicateCount,
            lastError: $tableModel->lastError,
            metadata: $tableModel->metadataJson !== null ? json_decode($tableModel->metadataJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $tableModel->createdAt ?? new \DateTimeImmutable(),
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof InboundDelivery || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookInboxTableModel(
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
