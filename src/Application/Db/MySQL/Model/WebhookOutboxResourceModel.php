<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;
use Semitexa\Orm\Metadata\HasRelationReferences;

#[FromTable(name: 'webhook_outbox')]
#[Index(
    columns: ['endpoint_definition_id', 'idempotency_key'],
    unique: true,
    name: 'uniq_webhook_outbox_endpoint_idempotency',
)]
final readonly class WebhookOutboxResourceModel
{
    use HasColumnReferences;
    use HasRelationReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,

        #[Column(name: 'endpoint_definition_id', type: MySqlType::Binary, length: 16)]
        public string $endpointDefinitionId,

        #[Column(name: 'endpoint_key', type: MySqlType::Varchar, length: 191)]
        public string $endpointKey,

        #[Column(name: 'provider_key', type: MySqlType::Varchar, length: 64)]
        public string $providerKey,

        #[Column(name: 'tenant_id', type: MySqlType::Varchar, length: 64, nullable: true)]
        public ?string $tenantId,

        #[Column(name: 'event_type', type: MySqlType::Varchar, length: 191)]
        public string $eventType,

        #[Column(type: MySqlType::Varchar, length: 32)]
        public string $status,

        #[Column(name: 'idempotency_key', type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $idempotencyKey,

        #[Column(name: 'payload_json', type: MySqlType::LongText)]
        public string $payloadJson,

        #[Column(name: 'headers_json', type: MySqlType::LongText, nullable: true)]
        public ?string $headersJson,

        #[Column(name: 'signed_headers_json', type: MySqlType::LongText, nullable: true)]
        public ?string $signedHeadersJson,

        #[Column(name: 'next_attempt_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $nextAttemptAt,

        #[Column(name: 'last_attempt_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $lastAttemptAt,

        #[Column(name: 'delivered_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $deliveredAt,

        #[Column(name: 'attempt_count', type: MySqlType::Int)]
        public int $attemptCount,

        #[Column(name: 'max_attempts', type: MySqlType::Int)]
        public int $maxAttempts,

        #[Column(name: 'initial_backoff_seconds', type: MySqlType::Int)]
        public int $initialBackoffSeconds,

        #[Column(name: 'max_backoff_seconds', type: MySqlType::Int)]
        public int $maxBackoffSeconds,

        #[Column(name: 'lease_owner', type: MySqlType::Varchar, length: 128, nullable: true)]
        public ?string $leaseOwner,

        #[Column(name: 'lease_expires_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $leaseExpiresAt,

        #[Column(name: 'last_response_status', type: MySqlType::Int, nullable: true)]
        public ?int $lastResponseStatus,

        #[Column(name: 'last_response_headers_json', type: MySqlType::LongText, nullable: true)]
        public ?string $lastResponseHeadersJson,

        #[Column(name: 'last_response_body', type: MySqlType::LongText, nullable: true)]
        public ?string $lastResponseBody,

        #[Column(name: 'last_error', type: MySqlType::LongText, nullable: true)]
        public ?string $lastError,

        #[Column(name: 'source_ref', type: MySqlType::Varchar, length: 255, nullable: true)]
        public ?string $sourceRef,

        #[Column(name: 'metadata_json', type: MySqlType::LongText, nullable: true)]
        public ?string $metadataJson,

        #[Column(name: 'created_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $createdAt,

        #[Column(name: 'updated_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $updatedAt,
    ) {}
}
