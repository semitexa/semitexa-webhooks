<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Enum\WebhookDirection;

#[AsMapper(
    resourceModel: WebhookAttemptResourceModel::class,
    domainModel: WebhookAttempt::class
)]
final class WebhookAttemptMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof WebhookAttemptResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new WebhookAttempt(
            id: $resourceModel->id,
            direction: WebhookDirection::from($resourceModel->direction),
            inboxId: $resourceModel->inboxId,
            outboxId: $resourceModel->outboxId,
            eventType: $resourceModel->eventType,
            attemptNumber: $resourceModel->attemptNumber,
            statusBefore: $resourceModel->statusBefore,
            statusAfter: $resourceModel->statusAfter,
            workerId: $resourceModel->workerId,
            httpStatus: $resourceModel->httpStatus,
            message: $resourceModel->message,
            details: $resourceModel->detailsJson !== null ? json_decode($resourceModel->detailsJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $resourceModel->createdAt,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof WebhookAttempt || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookAttemptResourceModel(
            id: $domainModel->id,
            direction: $domainModel->direction->value,
            inboxId: $domainModel->inboxId,
            outboxId: $domainModel->outboxId,
            eventType: $domainModel->eventType,
            attemptNumber: $domainModel->attemptNumber,
            statusBefore: $domainModel->statusBefore,
            statusAfter: $domainModel->statusAfter,
            workerId: $domainModel->workerId,
            httpStatus: $domainModel->httpStatus,
            message: $domainModel->message,
            detailsJson: $domainModel->details !== null ? json_encode($domainModel->details, JSON_THROW_ON_ERROR) : null,
            createdAt: $domainModel->createdAt,
        );
    }
}
