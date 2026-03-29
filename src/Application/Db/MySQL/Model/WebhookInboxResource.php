<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'webhook_inbox')]
#[Index(columns: ['dedupe_key'], name: 'uq_webhook_inbox_dedupe', unique: true)]
#[Index(columns: ['provider_key', 'provider_event_id'], name: 'idx_webhook_inbox_provider_event')]
#[Index(columns: ['status', 'last_received_at'], name: 'idx_webhook_inbox_status_received')]
#[Index(columns: ['tenant_id', 'status', 'last_received_at'], name: 'idx_webhook_inbox_tenant_status')]
#[Index(columns: ['endpoint_key', 'status', 'first_received_at'], name: 'idx_webhook_inbox_endpoint_status')]
class WebhookInboxResource
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Binary, length: 16)]
    public string $endpoint_definition_id = '';

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $provider_key = '';

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $endpoint_key = '';

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $provider_event_id = null;

    #[Column(type: MySqlType::Varchar, length: 255)]
    public string $dedupe_key = '';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $signature_status = 'pending';

    #[Column(type: MySqlType::Varchar, length: 32)]
    public string $status = 'received';

    #[Column(type: MySqlType::Varchar, length: 128, nullable: true)]
    public ?string $content_type = null;

    #[Column(type: MySqlType::Varchar, length: 16)]
    public string $http_method = 'POST';

    #[Column(type: MySqlType::Varchar, length: 2048)]
    public string $request_uri = '';

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $headers_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $raw_body = null;

    #[Column(type: MySqlType::Char, length: 64)]
    public string $raw_body_sha256 = '';

    #[Column(type: MySqlType::Varchar, length: 191, nullable: true)]
    public ?string $parsed_event_type = null;

    #[Column(type: MySqlType::Datetime)]
    public \DateTimeImmutable $first_received_at;

    #[Column(type: MySqlType::Datetime)]
    public \DateTimeImmutable $last_received_at;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $processing_started_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $processed_at = null;

    #[Column(type: MySqlType::Datetime, nullable: true)]
    public ?\DateTimeImmutable $failed_at = null;

    #[Column(type: MySqlType::Int)]
    public int $duplicate_count = 0;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $last_error = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $metadata_json = null;
}
