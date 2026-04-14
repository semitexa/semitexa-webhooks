<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'webhook_inbox')]
final readonly class WebhookInboxResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'endpoint_definition_id', type: MySqlType::Binary, length: 16)]
        public string $endpointDefinitionId,

        #[Column(name: 'provider_key', type: MySqlType::Varchar, length: 64)]
        public string $providerKey,

        #[Column(name: 'endpoint_key', type: MySqlType::Varchar, length: 191)]
        public string $endpointKey,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(name: 'provider_event_id', type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $providerEventId,

        #[Column(name: 'dedupe_key', type: MySqlType::Varchar, length: 255)]
        public string $dedupeKey,

        #[Column(name: 'signature_status', type: MySqlType::Varchar, length: 32)]
        public string $signatureStatus,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(name: 'content_type', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $contentType,

        #[Column(name: 'http_method', type: MySqlType::Varchar, length: 16)]
        public string $httpMethod,

        #[Column(name: 'request_uri', type: MySqlType::Varchar, length: 2048)]
        public string $requestUri,

        #[Column(name: 'headers_json', type: MySqlType::LongText, nullable: true)]
        public ?string $headersJson,

        #[Column(name: 'raw_body', type: MySqlType::LongText, nullable: true)]
        public ?string $rawBody,

        #[Column(name: 'raw_body_sha256', type: MySqlType::Char, length: 64)]
        public string $rawBodySha256,

        #[Column(name: 'parsed_event_type', type: MySqlType::Varchar, length: 191, nullable: true)]
        public ?string $parsedEventType,

        #[Column(name: 'first_received_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $firstReceivedAt,

        #[Column(name: 'last_received_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $lastReceivedAt,

        #[Column(name: 'processing_started_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $processingStartedAt,

        #[Column(name: 'processed_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $processedAt,

        #[Column(name: 'failed_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $failedAt,

        #[Column(name: 'duplicate_count', type: MySqlType::Int)]
        public int $duplicateCount,

        #[Column(name: 'last_error', type: MySqlType::LongText, nullable: true)]
        public ?string $lastError,

        #[Column(name: 'metadata_json', type: MySqlType::LongText, nullable: true)]
        public ?string $metadataJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,
    ) {}
}
