<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookPublisherInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\OutboundWebhookMessage;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;

#[SatisfiesServiceContract(of: WebhookPublisherInterface::class)]
final class WebhookPublisher implements WebhookPublisherInterface
{
    #[InjectAsReadonly]
    protected WebhookEndpointDefinitionRepositoryInterface $endpointRepo;

    #[InjectAsReadonly]
    protected OutboundDeliveryRepositoryInterface $outboxRepo;

    public function publish(OutboundWebhookMessage $message): void
    {
        $endpoint = $this->endpointRepo->findByEndpointKey($message->endpointKey);
        if ($endpoint === null) {
            throw new \RuntimeException("Unknown webhook endpoint: {$message->endpointKey}");
        }

        if (!$endpoint->enabled) {
            throw new \RuntimeException("Webhook endpoint is disabled: {$message->endpointKey}");
        }

        $delivery = new OutboundDelivery(
            id: Uuid7::generate(),
            endpointDefinitionId: $endpoint->id,
            endpointKey: $endpoint->endpointKey,
            providerKey: $endpoint->providerKey,
            tenantId: $endpoint->tenantId,
            eventType: $message->eventType,
            status: OutboundStatus::Pending,
            idempotencyKey: $message->idempotencyKey,
            payloadJson: json_encode($message->payload, JSON_THROW_ON_ERROR),
            headersJson: $message->headers !== [] ? json_encode($message->headers, JSON_THROW_ON_ERROR) : null,
            signedHeadersJson: null,
            nextAttemptAt: new \DateTimeImmutable(),
            maxAttempts: $endpoint->maxAttempts,
            initialBackoffSeconds: $endpoint->initialBackoffSeconds,
            maxBackoffSeconds: $endpoint->maxBackoffSeconds,
            sourceRef: $message->sourceRef,
        );

        // Idempotent insert when the message carries an idempotency key:
        // the (endpoint_definition_id, idempotency_key) UNIQUE constraint
        // on webhook_outbox guarantees that two concurrent publishers
        // produce exactly one row. Optional-idempotency policy: NULL
        // keys always insert a fresh row.
        $this->outboxRepo->insertOrMatchIdempotency($delivery);
    }
}
