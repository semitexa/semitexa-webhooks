<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Enum\WebhookDirection;

#[AsMapper(
    resourceModel: WebhookAttemptTableModel::class,
    domainModel: WebhookAttempt::class
)]
final class WebhookAttemptMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof WebhookAttemptTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new WebhookAttempt(
            id: $tableModel->id,
            direction: WebhookDirection::from($tableModel->direction),
            inboxId: $tableModel->inboxId,
            outboxId: $tableModel->outboxId,
            eventType: $tableModel->eventType,
            attemptNumber: $tableModel->attemptNumber,
            statusBefore: $tableModel->statusBefore,
            statusAfter: $tableModel->statusAfter,
            workerId: $tableModel->workerId,
            httpStatus: $tableModel->httpStatus,
            message: $tableModel->message,
            details: $tableModel->detailsJson !== null ? json_decode($tableModel->detailsJson, true, 512, JSON_THROW_ON_ERROR) : null,
            createdAt: $tableModel->createdAt,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof WebhookAttempt || throw new \InvalidArgumentException('Unexpected domain model.');

        return new WebhookAttemptTableModel(
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
