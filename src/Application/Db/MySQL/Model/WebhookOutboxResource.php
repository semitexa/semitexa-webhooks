<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Contract\DomainMappable;
use Semitexa\Orm\Trait\HasTimestamps;
use Semitexa\Orm\Trait\HasUuidV7;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Enum\OutboundStatus;

#[FromTable(name: 'webhook_outbox', mapTo: OutboundDelivery::class)]
#[Index(columns: ['status', 'next_attempt_at'], name: 'idx_webhook_outbox_status_next')]
#[Index(columns: ['tenant_id', 'status', 'next_attempt_at'], name: 'idx_webhook_outbox_tenant_status')]
#[Index(columns: ['endpoint_key', 'status', 'next_attempt_at'], name: 'idx_webhook_outbox_endpoint_status')]
#[Index(columns: ['status', 'lease_expires_at'], name: 'idx_webhook_outbox_lease')]
class WebhookOutboxResource implements DomainMappable
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

    public function toDomain(): OutboundDelivery
    {
        return new OutboundDelivery(
            id: $this->id,
            endpointDefinitionId: $this->endpoint_definition_id,
            endpointKey: $this->endpoint_key,
            providerKey: $this->provider_key,
            tenantId: $this->tenant_id,
            eventType: $this->event_type,
            status: OutboundStatus::from($this->status),
            idempotencyKey: $this->idempotency_key,
            payloadJson: $this->payload_json,
            headersJson: $this->headers_json,
            signedHeadersJson: $this->signed_headers_json,
            nextAttemptAt: $this->next_attempt_at,
            lastAttemptAt: $this->last_attempt_at,
            deliveredAt: $this->delivered_at,
            attemptCount: $this->attempt_count,
            maxAttempts: $this->max_attempts,
            initialBackoffSeconds: $this->initial_backoff_seconds,
            maxBackoffSeconds: $this->max_backoff_seconds,
            leaseOwner: $this->lease_owner,
            leaseExpiresAt: $this->lease_expires_at,
            lastResponseStatus: $this->last_response_status,
            lastResponseHeadersJson: $this->last_response_headers_json,
            lastResponseBody: $this->last_response_body,
            lastError: $this->last_error,
            sourceRef: $this->source_ref,
            metadata: $this->metadata_json !== null ? json_decode($this->metadata_json, true) : null,
            createdAt: $this->created_at,
        );
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof OutboundDelivery);

        $resource = new static();
        $resource->id = $entity->getId();
        $resource->endpoint_definition_id = $entity->getEndpointDefinitionId();
        $resource->endpoint_key = $entity->getEndpointKey();
        $resource->provider_key = $entity->getProviderKey();
        $resource->tenant_id = $entity->getTenantId();
        $resource->event_type = $entity->getEventType();
        $resource->status = $entity->getStatus()->value;
        $resource->idempotency_key = $entity->getIdempotencyKey();
        $resource->payload_json = $entity->getPayloadJson();
        $resource->headers_json = $entity->getHeadersJson();
        $resource->signed_headers_json = $entity->getSignedHeadersJson();
        $resource->next_attempt_at = $entity->getNextAttemptAt();
        $resource->last_attempt_at = $entity->getLastAttemptAt();
        $resource->delivered_at = $entity->getDeliveredAt();
        $resource->attempt_count = $entity->getAttemptCount();
        $resource->max_attempts = $entity->getMaxAttempts();
        $resource->initial_backoff_seconds = $entity->getInitialBackoffSeconds();
        $resource->max_backoff_seconds = $entity->getMaxBackoffSeconds();
        $resource->lease_owner = $entity->getLeaseOwner();
        $resource->lease_expires_at = $entity->getLeaseExpiresAt();
        $resource->last_response_status = $entity->getLastResponseStatus();
        $resource->last_response_headers_json = $entity->getLastResponseHeadersJson();
        $resource->last_response_body = $entity->getLastResponseBody();
        $resource->last_error = $entity->getLastError();
        $resource->source_ref = $entity->getSourceRef();
        $resource->metadata_json = $entity->getMetadata() !== null ? json_encode($entity->getMetadata(), JSON_THROW_ON_ERROR) : null;
        $resource->created_at = $entity->getCreatedAt();

        return $resource;
    }
}
