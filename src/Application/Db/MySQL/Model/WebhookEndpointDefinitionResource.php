<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;

#[FromTable(name: 'webhook_endpoint_definitions')]
#[Index(columns: ['endpoint_key'], unique: true, name: 'uq_webhook_endpoint_definitions_key')]
#[Index(columns: ['provider_key', 'direction', 'enabled'], name: 'idx_webhook_endpoint_definitions_provider')]
#[Index(columns: ['tenant_id', 'direction', 'enabled'], name: 'idx_webhook_endpoint_definitions_tenant')]
class WebhookEndpointDefinitionResource
{
    use HasUuidV7;
    use HasTimestamps;

    #[Column(type: MySqlType::Varchar, length: 191)]
    public string $endpoint_key = '';

    #[Column(type: MySqlType::Varchar, length: 16)]
    public string $direction = 'inbound';

    #[Column(type: MySqlType::Varchar, length: 64)]
    public string $provider_key = '';

    #[Column(type: MySqlType::TinyInt)]
    public int $enabled = 1;

    #[Column(type: MySqlType::Varchar, length: 64, nullable: true)]
    public ?string $tenant_id = null;

    #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
    public ?string $verification_mode = null;

    #[Column(type: MySqlType::Varchar, length: 32, nullable: true)]
    public ?string $signing_mode = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $secret_ref = null;

    #[Column(type: MySqlType::Varchar, length: 2048, nullable: true)]
    public ?string $target_url = null;

    #[Column(type: MySqlType::Int)]
    public int $timeout_seconds = 10;

    #[Column(type: MySqlType::Int)]
    public int $max_attempts = 5;

    #[Column(type: MySqlType::Int)]
    public int $initial_backoff_seconds = 30;

    #[Column(type: MySqlType::Int)]
    public int $max_backoff_seconds = 3600;

    #[Column(type: MySqlType::Int, nullable: true)]
    public ?int $dedupe_window_seconds = null;

    #[Column(type: MySqlType::Varchar, length: 255, nullable: true)]
    public ?string $handler_class = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $default_headers_json = null;

    #[Column(type: MySqlType::LongText, nullable: true)]
    public ?string $metadata_json = null;
}
