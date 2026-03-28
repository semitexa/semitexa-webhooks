<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'webhook_attempts')]
#[Index(columns: ['direction', 'inbox_id', 'created_at'], name: 'idx_webhook_attempts_inbound')]
#[Index(columns: ['direction', 'outbox_id', 'created_at'], name: 'idx_webhook_attempts_outbound')]
#[Index(columns: ['event_type', 'created_at'], name: 'idx_webhook_attempts_event')]
class WebhookAttemptResource
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
}
