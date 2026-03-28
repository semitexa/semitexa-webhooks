<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Enum\OutboundStatus;

#[AsMapper(tableModel: WebhookOutboxTableModel::class, domainModel: OutboundDelivery::class)]
final class WebhookOutboxMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof WebhookOutboxTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new OutboundDelivery(
            id: $tableModel->id,
            endpointDefinitionId: $tableModel->endpointDefinitionId,
            endpointKey: $tableModel->endpointKey,
            providerKey: $tableModel->providerKey,
            tenantId: $tableModel->tenantId,
            eventType: $tableModel->eventType,
            status: OutboundStatus::from($tableModel->status),
            idempotencyKey: $tableModel->idempotencyKey,
            payloadJson: $tableModel->payloadJson,
            headersJson: $tableModel->headersJson,
            signedHeadersJson: $tableModel->signedHeadersJson,
            nextAttemptAt: $tableModel->nextAttemptAt,
            lastAttemptAt: $tableModel->lastAttemptAt,
            deliveredAt: $tableModel->deliveredAt,
            attemptCount: $tableModel->attemptCount,
            maxAttempts: $tableModel->maxAttempts,
            initialBackoffSeconds: $tableModel->initialBackoffSeconds,
            maxBackoffSeconds: $tableModel->maxBackoffSeconds,
            leaseOwner: $tableModel->leaseOwner,
            leaseExpiresAt: $tableModel->leaseExpiresAt,
            lastResponseStatus: $tableModel->lastResponseStatus,
            lastResponseHeadersJson: $tableModel->lastResponseHeadersJson,
            lastResponseBody: $tableModel->lastResponseBody,
            lastError: $tableModel->lastError,
            sourceRef: $tableModel->sourceRef,
            metadata: $tableModel->metadataJson !== null ? json_decode($tableModel->metadataJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $tableModel->createdAt ?? new \DateTimeImmutable(),
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof OutboundDelivery || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookOutboxTableModel(
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
            updatedAt: null,
        );
    }
}
