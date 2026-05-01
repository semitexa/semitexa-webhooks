<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookInboundProcessorInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookSignatureVerifierInterface;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookVerificationInput;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\SignatureStatus;

final class InboundWebhookReceiver
{
    #[InjectAsReadonly]
    protected WebhookEndpointDefinitionRepositoryInterface $endpointRepo;

    #[InjectAsReadonly]
    protected WebhookSignatureVerifierInterface $verifier;

    #[InjectAsReadonly]
    protected InboundDeliveryRepositoryInterface $inboxRepo;

    #[InjectAsReadonly]
    protected InboundWebhookService $inboundService;

    #[InjectAsReadonly]
    protected InboundDedupeKeyFactory $dedupeKeyFactory;

    public function receive(InboundWebhookEnvelope $envelope, ?WebhookInboundProcessorInterface $processor = null): InboundDelivery
    {
        // 1. Resolve endpoint definition
        $endpoint = $this->endpointRepo->findByEndpointKey($envelope->endpointKey);
        if ($endpoint === null) {
            throw new \RuntimeException("Unknown webhook endpoint: {$envelope->endpointKey}");
        }

        if (!$endpoint->enabled) {
            throw new \RuntimeException("Webhook endpoint is disabled: {$envelope->endpointKey}");
        }

        // 2. Build verification input and verify
        $verificationInput = new WebhookVerificationInput(
            headers: $envelope->headers,
            rawBody: $envelope->rawBody,
            secretRef: $endpoint->secretRef,
            verificationMode: $endpoint->verificationMode,
        );

        $verificationResult = $this->verifier->verify($verificationInput);

        // 3. Build dedupe key
        $providerEventId = $this->extractProviderEventId($envelope);
        $rawBodySha256 = hash('sha256', $envelope->rawBody);
        $dedupeKey = $this->dedupeKeyFactory->generate(
            $endpoint->providerKey,
            $endpoint->endpointKey,
            $providerEventId,
            $envelope->rawBody,
            $endpoint->tenantId,
        );

        // 4. Build inbound delivery
        $now = new \DateTimeImmutable();
        $delivery = new InboundDelivery(
            id: Uuid7::generate(),
            endpointDefinitionId: $endpoint->id,
            providerKey: $endpoint->providerKey,
            endpointKey: $endpoint->endpointKey,
            tenantId: $endpoint->tenantId,
            providerEventId: $providerEventId,
            dedupeKey: $dedupeKey,
            signatureStatus: $verificationResult->verified ? SignatureStatus::Verified : SignatureStatus::Rejected,
            status: InboundStatus::Received,
            contentType: $envelope->contentType,
            httpMethod: $envelope->httpMethod,
            requestUri: $envelope->requestUri,
            headers: $envelope->headers,
            rawBody: $envelope->rawBody,
            rawBodySha256: $rawBodySha256,
            parsedEventType: $this->extractEventType($envelope),
            firstReceivedAt: $now,
            lastReceivedAt: $now,
        );

        // 5. Deduplicate
        $delivery = $this->inboxRepo->insertOrMatchDedupe($delivery);

        if ($delivery->getStatus() === InboundStatus::DuplicateIgnored) {
            return $delivery;
        }

        // 6. Handle signature rejection
        if (!$verificationResult->verified) {
            $this->inboundService->markRejectedSignature($delivery, $verificationResult->reason);
            return $delivery;
        }

        $this->inboundService->markVerified($delivery);

        // 7. Dispatch to processor if provided
        if ($processor !== null) {
            $this->inboundService->markProcessing($delivery);
            try {
                $processor->process($envelope);
                $this->inboundService->markProcessed($delivery);
            } catch (\Throwable $e) {
                $this->inboundService->markFailed($delivery, $e->getMessage());
            }
        }

        return $delivery;
    }

    private function extractProviderEventId(InboundWebhookEnvelope $envelope): ?string
    {
        $headers = $envelope->headers;
        $lower = [];
        foreach ($headers as $key => $value) {
            $lower[strtolower((string) $key)] = is_array($value) ? ($value[0] ?? null) : (string) $value;
        }

        return $lower['x-webhook-event-id']
            ?? $lower['x-github-delivery']
            ?? $lower['stripe-webhook-id']
            ?? null;
    }

    private function extractEventType(InboundWebhookEnvelope $envelope): ?string
    {
        $headers = $envelope->headers;
        $lower = [];
        foreach ($headers as $key => $value) {
            $lower[strtolower((string) $key)] = is_array($value) ? ($value[0] ?? null) : (string) $value;
        }

        if (isset($lower['x-webhook-event-type'])) {
            return $lower['x-webhook-event-type'];
        }
        if (isset($lower['x-github-event'])) {
            return $lower['x-github-event'];
        }

        // Try to extract from JSON body
        if ($envelope->contentType !== null && str_contains($envelope->contentType, 'json')) {
            $data = json_decode($envelope->rawBody, true);
            if (is_array($data) && isset($data['type'])) {
                return (string) $data['type'];
            }
        }

        return null;
    }
}
