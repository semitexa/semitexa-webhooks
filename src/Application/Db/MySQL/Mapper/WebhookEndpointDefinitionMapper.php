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
            id: $domainModel->getId(),
            endpointKey: $domainModel->getEndpointKey(),
            direction: $domainModel->getDirection()->value,
            providerKey: $domainModel->getProviderKey(),
            enabled: $domainModel->isEnabled(),
            tenantId: $domainModel->getTenantId(),
            verificationMode: $domainModel->getVerificationMode(),
            signingMode: $domainModel->getSigningMode(),
            secretRef: $domainModel->getSecretRef(),
            targetUrl: $domainModel->getTargetUrl(),
            timeoutSeconds: $domainModel->getTimeoutSeconds(),
            maxAttempts: $domainModel->getMaxAttempts(),
            initialBackoffSeconds: $domainModel->getInitialBackoffSeconds(),
            maxBackoffSeconds: $domainModel->getMaxBackoffSeconds(),
            dedupeWindowSeconds: $domainModel->getDedupeWindowSeconds(),
            handlerClass: $domainModel->getHandlerClass(),
            defaultHeadersJson: $domainModel->getDefaultHeaders() !== null ? json_encode($domainModel->getDefaultHeaders(), JSON_THROW_ON_ERROR) : null,
            metadataJson: $domainModel->getMetadata() !== null ? json_encode($domainModel->getMetadata(), JSON_THROW_ON_ERROR) : null,
            createdAt: $domainModel->getCreatedAt(),
            updatedAt: $domainModel->getUpdatedAt(),
        );
    }
}
