<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Trait\HasUuidV7;
use Semitexa\Webhooks\Domain\Model\WebhookAttempt;
use Semitexa\Webhooks\Enum\WebhookDirection;

#[FromTable(name: 'webhook_attempts', mapTo: WebhookAttempt::class)]
#[Index(columns: ['direction', 'inbox_id', 'created_at'], name: 'idx_webhook_attempts_inbound')]
#[Index(columns: ['direction', 'outbox_id', 'created_at'], name: 'idx_webhook_attempts_outbound')]
#[Index(columns: ['event_type', 'created_at'], name: 'idx_webhook_attempts_event')]
class WebhookAttemptResource implements DomainMappable
{
    use HasUuidV7;

    #[Column(type: MySqlType::Varchar, length: 16)]
    public string $direction = 'inbound';

    #[Column(type: MySqlType::Binary, length: 16, nullable: true)]
    public ?string $inbox_id = null;

    #[Column(type: MySqlType::Binary, length: 16, nullable: true)]
    public ?string $outbox_id = null;

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $event_type = '';

    #[Column(type: MySqlType::Int, nullable: true)]
    public ?int $attempt_number = null;

    #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
    public ?string $status_before = null;

    #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
    public ?string $status_after = null;

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $worker_id = null;

    #[Column(type: MySqlType::Int, nullable: true)]
    public ?int $http_status = null;

    #[Column(type: MySqlType::Varchar, length: 512, nullable: true)]
    public ?string $message = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $details_json = null;

    #[Column(type: MySqlType::Datetime)]
    public \DateTimeImmutable $created_at;

    public function toDomain(): WebhookAttempt
    {
        return new WebhookAttempt(
            id: $this->id,
            direction: WebhookDirection::from($this->direction),
            inboxId: $this->inbox_id,
            outboxId: $this->outbox_id,
            eventType: $this->event_type,
            attemptNumber: $this->attempt_number,
            statusBefore: $this->status_before,
            statusAfter: $this->status_after,
            workerId: $this->worker_id,
            httpStatus: $this->http_status,
            message: $this->message,
            details: $this->details_json !== null ? json_decode($this->details_json, true) : null,
            createdAt: $this->created_at,
        );
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof WebhookAttempt);

        $resource = new static();
        $resource->id = $entity->id;
        $resource->direction = $entity->direction->value;
        $resource->inbox_id = $entity->inboxId;
        $resource->outbox_id = $entity->outboxId;
        $resource->event_type = $entity->eventType;
        $resource->attempt_number = $entity->attemptNumber;
        $resource->status_before = $entity->statusBefore;
        $resource->status_after = $entity->statusAfter;
        $resource->worker_id = $entity->workerId;
        $resource->http_status = $entity->httpStatus;
        $resource->message = $entity->message;
        $resource->details_json = $entity->details !== null ? json_encode($entity->details, JSON_THROW_ON_ERROR) : null;
        $resource->created_at = $entity->createdAt;

        return $resource;
    }
}
