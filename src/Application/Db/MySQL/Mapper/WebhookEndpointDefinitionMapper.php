<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Webhooks\Application\Db\MySQL\Model\WebhookEndpointDefinitionResourceModel;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;

#[AsMapper(resourceModel: WebhookEndpointDefinitionResourceModel::class, domainModel: WebhookEndpointDefinition::class)]
final class WebhookEndpointDefinitionMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WebhookEndpointDefinitionResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');
        if ($resourceModel->createdAt === null || $resourceModel->updatedAt === null) {
            throw new \InvalidArgumentException('Webhook endpoint definition timestamps must not be null.');
        }

        return new WebhookEndpointDefinition(
            id: $resourceModel->id,
            endpointKey: $resourceModel->endpointKey,
            direction: WebhookDirection::from($resourceModel->direction),
            providerKey: $resourceModel->providerKey,
            enabled: $resourceModel->enabled,
            tenantId: $resourceModel->tenantId,
            verificationMode: $resourceModel->verificationMode,
            signingMode: $resourceModel->signingMode,
            secretRef: $resourceModel->secretRef,
            targetUrl: $resourceModel->targetUrl,
            timeoutSeconds: $resourceModel->timeoutSeconds,
            maxAttempts: $resourceModel->maxAttempts,
            initialBackoffSeconds: $resourceModel->initialBackoffSeconds,
            maxBackoffSeconds: $resourceModel->maxBackoffSeconds,
            dedupeWindowSeconds: $resourceModel->dedupeWindowSeconds,
            handlerClass: $resourceModel->handlerClass,
            defaultHeaders: $resourceModel->defaultHeadersJson !== null ? json_decode($resourceModel->defaultHeadersJson, true, 512, JSON_THROW_ON_ERROR) : null,
            metadata: $resourceModel->metadataJson !== null ? json_decode($resourceModel->metadataJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $resourceModel->createdAt,
            updatedAt: $resourceModel->updatedAt,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof WebhookEndpointDefinition || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookEndpointDefinitionResourceModel(
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
