<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'webhook_outbox')]
#[Index(columns: ['status', 'next_attempt_at'], name: 'idx_webhook_outbox_status_next')]
#[Index(columns: ['tenant_id', 'status', 'next_attempt_at'], name: 'idx_webhook_outbox_tenant_status')]
#[Index(columns: ['endpoint_key', 'status', 'next_attempt_at'], name: 'idx_webhook_outbox_endpoint_status')]
#[Index(columns: ['status', 'lease_expires_at'], name: 'idx_webhook_outbox_lease')]
class WebhookOutboxResource
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Binary, length: 16)]
    public string $endpoint_definition_id = '';

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $endpoint_key = '';

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $provider_key = '';

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $event_type = '';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $status = 'pending';

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $idempotency_key = null;

    #[Column(type: MySqlType::LongText)]
    public string $payload_json = '{}';

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $headers_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $signed_headers_json = null;

    #[Column(type: MySqlType::Datetime)]
    public \DateTimeImmutable $next_attempt_at;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $last_attempt_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $delivered_at = null;

    #[Column(type: MySqlType::Int)]
    public int $attempt_count = 0;

    #[Column(type: MySqlType::Int)]
    public int $max_attempts = 5;

    #[Column(type: MySqlType::Int)]
    public int $initial_backoff_seconds = 30;

    #[Column(type: MySqlType::Int)]
    public int $max_backoff_seconds = 3600;

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $lease_owner = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $lease_expires_at = null;

    #[Column(type: MySqlType::Int, nullable: true)]
    public ?int $last_response_status = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $last_response_headers_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $last_response_body = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $last_error = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $source_ref = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $metadata_json = null;
}
