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
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Enum\WebhookDirection;

#[FromTable(name: 'webhook_endpoint_definitions', mapTo: WebhookEndpointDefinition::class)]
#[Index(columns: ['endpoint_key'], unique: true, name: 'uq_webhook_endpoint_definitions_key')]
#[Index(columns: ['provider_key', 'direction', 'enabled'], name: 'idx_webhook_endpoint_definitions_provider')]
#[Index(columns: ['tenant_id', 'direction', 'enabled'], name: 'idx_webhook_endpoint_definitions_tenant')]
class WebhookEndpointDefinitionResource implements DomainMappable
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

    public function toDomain(): WebhookEndpointDefinition
    {
        return new WebhookEndpointDefinition(
            id: $this->id,
            endpointKey: $this->endpoint_key,
            direction: WebhookDirection::from($this->direction),
            providerKey: $this->provider_key,
            enabled: (bool) $this->enabled,
            tenantId: $this->tenant_id,
            verificationMode: $this->verification_mode,
            signingMode: $this->signing_mode,
            secretRef: $this->secret_ref,
            targetUrl: $this->target_url,
            timeoutSeconds: $this->timeout_seconds,
            maxAttempts: $this->max_attempts,
            initialBackoffSeconds: $this->initial_backoff_seconds,
            maxBackoffSeconds: $this->max_backoff_seconds,
            dedupeWindowSeconds: $this->dedupe_window_seconds,
            handlerClass: $this->handler_class,
            defaultHeaders: $this->default_headers_json !== null ? json_decode($this->default_headers_json, true) : null,
            metadata: $this->metadata_json !== null ? json_decode($this->metadata_json, true) : null,
            createdAt: $this->created_at,
            updatedAt: $this->updated_at,
        );
    }

    public static function fromDomain(object $entity): static
    {
        assert($entity instanceof WebhookEndpointDefinition);

        $resource = new static();
        $resource->id = $entity->id;
        $resource->endpoint_key = $entity->endpointKey;
        $resource->direction = $entity->direction->value;
        $resource->provider_key = $entity->providerKey;
        $resource->enabled = $entity->enabled ? 1 : 0;
        $resource->tenant_id = $entity->tenantId;
        $resource->verification_mode = $entity->verificationMode;
        $resource->signing_mode = $entity->signingMode;
        $resource->secret_ref = $entity->secretRef;
        $resource->target_url = $entity->targetUrl;
        $resource->timeout_seconds = $entity->timeoutSeconds;
        $resource->max_attempts = $entity->maxAttempts;
        $resource->initial_backoff_seconds = $entity->initialBackoffSeconds;
        $resource->max_backoff_seconds = $entity->maxBackoffSeconds;
        $resource->dedupe_window_seconds = $entity->dedupeWindowSeconds;
        $resource->handler_class = $entity->handlerClass;
        $resource->default_headers_json = $entity->defaultHeaders !== null ? json_encode($entity->defaultHeaders, JSON_THROW_ON_ERROR) : null;
        $resource->metadata_json = $entity->metadata !== null ? json_encode($entity->metadata, JSON_THROW_ON_ERROR) : null;
        $resource->created_at = $entity->createdAt;
        $resource->updated_at = $entity->updatedAt;

        return $resource;
    }
}
