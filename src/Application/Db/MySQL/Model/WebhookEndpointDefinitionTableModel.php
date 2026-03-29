<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'webhook_endpoint_definitions')]
final readonly class WebhookEndpointDefinitionTableModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'endpoint_key', type: MySqlType::Varchar, length: 191)]
        public string $endpointKey,

        #[Column(type: MySqlType::Varchar, length: 16)]
        public string $direction,

        #[Column(name: 'provider_key', type: MySqlType::Varchar, length: 64)]
        public string $providerKey,

        #[Column(type: MySqlType::Boolean)]
        public bool $enabled,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(name: 'verification_mode', type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $verificationMode,

        #[Column(name: 'signing_mode', type: MySqlType::Varchar, length: 32, nullable: true)]
        public ?string $signingMode,

        #[Column(name: 'secret_ref', type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $secretRef,

        #[Column(name: 'target_url', type: MySqlType::Varchar, length: 2048, nullable: true)]
        public ?string $targetUrl,

        #[Column(name: 'timeout_seconds', type: MySqlType::Int)]
        public int $timeoutSeconds,

        #[Column(name: 'max_attempts', type: MySqlType::Int)]
        public int $maxAttempts,

        #[Column(name: 'initial_backoff_seconds', type: MySqlType::Int)]
        public int $initialBackoffSeconds,

        #[Column(name: 'max_backoff_seconds', type: MySqlType::Int)]
        public int $maxBackoffSeconds,

        #[Column(name: 'dedupe_window_seconds', type: MySqlType::Int, nullable: true)]
        public ?int $dedupeWindowSeconds,

        #[Column(name: 'handler_class', type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $handlerClass,

        #[Column(name: 'default_headers_json', type: MySqlType::LongText, nullable: true)]
        public ?string $defaultHeadersJson,

        #[Column(name: 'metadata_json', type: MySqlType::LongText, nullable: true)]
        public ?string $metadataJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,
    ) {}
}
