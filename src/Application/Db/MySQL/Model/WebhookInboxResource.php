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
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Enum\InboundStatus;
use Semitexa\Webhooks\Enum\SignatureStatus;

#[FromTable(name: 'webhook_inbox', mapTo: InboundDelivery::class)]
#[Index(columns: ['dedupe_key'], name: 'uq_webhook_inbox_dedupe', unique: true)]
#[Index(columns: ['provider_key', 'provider_event_id'], name: 'idx_webhook_inbox_provider_event')]
#[Index(columns: ['status', 'last_received_at'], name: 'idx_webhook_inbox_status_received')]
#[Index(columns: ['tenant_id', 'status', 'last_received_at'], name: 'idx_webhook_inbox_tenant_status')]
#[Index(columns: ['endpoint_key', 'status', 'first_received_at'], name: 'idx_webhook_inbox_endpoint_status')]
class WebhookInboxResource implements DomainMappable
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

    public function toDomain(): InboundDelivery
    {
        return new InboundDelivery(
            id: $this->id,
            endpointDefinitionId: $this->endpoint_definition_id,
            providerKey: $this->provider_key,
            endpointKey: $this->endpoint_key,
            tenantId: $this->tenant_id,
            providerEventId: $this->provider_event_id,
            dedupeKey: $this->dedupe_key,
            signatureStatus: SignatureStatus::from($this->signature_status),
            status: InboundStatus::from($this->status),
            contentType: $this->content_type,
            httpMethod: $this->http_method,
            requestUri: $this->request_uri,
            headers: $this->headers_json !== null ? json_decode($this->headers_json, true) : null,
            rawBody: $this->raw_body,
            rawBodySha256: $this->raw_body_sha256,
            parsedEventType: $this->parsed_event_type,
            firstReceivedAt: $this->first_received_at,
            lastReceivedAt: $this->last_received_at,
            processingStartedAt: $this->processing_started_at,
            processedAt: $this->processed_at,
            failedAt: $this->failed_at,
            duplicateCount: $this->duplicate_count,
            lastError: $this->last_error,
            metadata: $this->metadata_json !== null ? json_decode($this->metadata_json, true) : null,
            createdAt: $this->created_at,
        );
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof InboundDelivery);

        $resource = new static();
        $resource->id = $entity->getId();
        $resource->endpoint_definition_id = $entity->getEndpointDefinitionId();
        $resource->provider_key = $entity->getProviderKey();
        $resource->endpoint_key = $entity->getEndpointKey();
        $resource->tenant_id = $entity->getTenantId();
        $resource->provider_event_id = $entity->getProviderEventId();
        $resource->dedupe_key = $entity->getDedupeKey();
        $resource->signature_status = $entity->getSignatureStatus()->value;
        $resource->status = $entity->getStatus()->value;
        $resource->content_type = $entity->getContentType();
        $resource->http_method = $entity->getHttpMethod();
        $resource->request_uri = $entity->getRequestUri();
        $resource->headers_json = $entity->getHeaders() !== null ? json_encode($entity->getHeaders(), JSON_THROW_ON_ERROR) : null;
        $resource->raw_body = $entity->getRawBody();
        $resource->raw_body_sha256 = $entity->getRawBodySha256();
        $resource->parsed_event_type = $entity->getParsedEventType();
        $resource->first_received_at = $entity->getFirstReceivedAt();
        $resource->last_received_at = $entity->getLastReceivedAt();
        $resource->processing_started_at = $entity->getProcessingStartedAt();
        $resource->processed_at = $entity->getProcessedAt();
        $resource->failed_at = $entity->getFailedAt();
        $resource->duplicate_count = $entity->getDuplicateCount();
        $resource->last_error = $entity->getLastError();
        $resource->metadata_json = $entity->getMetadata() !== null ? json_encode($entity->getMetadata(), JSON_THROW_ON_ERROR) : null;
        $resource->created_at = $entity->getCreatedAt();

        return $resource;
    }
}
