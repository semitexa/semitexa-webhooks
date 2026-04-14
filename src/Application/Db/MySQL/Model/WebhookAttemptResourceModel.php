<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'webhook_attempts')]
final readonly class WebhookAttemptResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $direction,

        #[Column(name: 'inbox_id', type: MySqlType::Binary, length: 16, nullable: true)]
        public ?string $inboxId,

        #[Column(name: 'outbox_id', type: MySqlType::Binary, length: 16, nullable: true)]
        public ?string $outboxId,

        #[Column(name: 'event_type', type: MySqlType::Varchar, length: 64)]
        public string $eventType,

        #[Column(name: 'attempt_number', type: MySqlType::Int, nullable: true)]
        public ?int $attemptNumber,

        #[Column(name: 'status_before', type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $statusBefore,

        #[Column(name: 'status_after', type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $statusAfter,

        #[Column(name: 'worker_id', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $workerId,

        #[Column(name: 'http_status', type: MySqlType::Int, nullable: true)]
        public ?int $httpStatus,

        #[Column(type: MySqlType::Varchar, length: 512, nullable: true)]
        public ?string $message,

        #[Column(name: 'details_json', type: MySqlType::LongText, nullable: true)]
        public ?string $detailsJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $createdAt,
    ) {}
}
