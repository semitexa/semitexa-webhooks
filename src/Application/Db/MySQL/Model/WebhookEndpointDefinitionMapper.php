<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Enum\WebhookDirection;

#[AsMapper(resourceModel: WebhookEndpointDefinitionTableModel::class, domainModel: WebhookEndpointDefinition::class)]
final class WebhookEndpointDefinitionMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof WebhookEndpointDefinitionTableModel || throw new \InvalidArgumentException('Unexpected table model.');
        if ($tableModel->createdAt === null || $tableModel->updatedAt === null) {
            throw new \InvalidArgumentException('Webhook endpoint definition timestamps must not be null.');
        }

        return new WebhookEndpointDefinition(
            id: $tableModel->id,
            endpointKey: $tableModel->endpointKey,
            direction: WebhookDirection::from($tableModel->direction),
            providerKey: $tableModel->providerKey,
            enabled: $tableModel->enabled,
            tenantId: $tableModel->tenantId,
            verificationMode: $tableModel->verificationMode,
            signingMode: $tableModel->signingMode,
            secretRef: $tableModel->secretRef,
            targetUrl: $tableModel->targetUrl,
            timeoutSeconds: $tableModel->timeoutSeconds,
            maxAttempts: $tableModel->maxAttempts,
            initialBackoffSeconds: $tableModel->initialBackoffSeconds,
            maxBackoffSeconds: $tableModel->maxBackoffSeconds,
            dedupeWindowSeconds: $tableModel->dedupeWindowSeconds,
            handlerClass: $tableModel->handlerClass,
            defaultHeaders: $tableModel->defaultHeadersJson !== null ? json_decode($tableModel->defaultHeadersJson, true, 512, JSON_THROW_ON_ERROR) : null,
            metadata: $tableModel->metadataJson !== null ? json_decode($tableModel->metadataJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $tableModel->createdAt,
            updatedAt: $tableModel->updatedAt,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof WebhookEndpointDefinition || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookEndpointDefinitionTableModel(
            id: $domainModel->id,
            endpointKey: $domainModel->endpointKey,
            direction: $domainModel->direction->value,
            providerKey: $domainModel->providerKey,
            enabled: $domainModel->enabled,
            tenantId: $domainModel->tenantId,
            verificationMode: $domainModel->verificationMode,
            signingMode: $domainModel->signingMode,
            secretRef: $domainModel->secretRef,
            targetUrl: $domainModel->targetUrl,
            timeoutSeconds: $domainModel->timeoutSeconds,
            maxAttempts: $domainModel->maxAttempts,
            initialBackoffSeconds: $domainModel->initialBackoffSeconds,
            maxBackoffSeconds: $domainModel->maxBackoffSeconds,
            dedupeWindowSeconds: $domainModel->dedupeWindowSeconds,
            handlerClass: $domainModel->handlerClass,
            defaultHeadersJson: $domainModel->defaultHeaders !== null ? json_encode($domainModel->defaultHeaders, JSON_THROW_ON_ERROR) : null,
            metadataJson: $domainModel->metadata !== null ? json_encode($domainModel->metadata, JSON_THROW_ON_ERROR) : null,
            createdAt: $domainModel->createdAt,
            updatedAt: $domainModel->updatedAt,
        );
    }
}
