<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

use Semitexa\Core\Attribute\AsService;
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

#[AsService]
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

        if (!$endpoint->isEnabled()) {
            throw new \RuntimeException("Webhook endpoint is disabled: {$envelope->endpointKey}");
        }

        // 2. Build verification input and verify
        $verificationInput = new WebhookVerificationInput(
            headers: $envelope->headers,
            rawBody: $envelope->rawBody,
            secretRef: $endpoint->getSecretRef(),
            verificationMode: $endpoint->getVerificationMode(),
            tenantId: $endpoint->getTenantId(),
        );

        $verificationResult = $this->verifier->verify($verificationInput);

        // 3. Build dedupe key
        $providerEventId = $this->extractProviderEventId($envelope);
        $rawBodySha256 = hash('sha256', $envelope->rawBody);
        $dedupeKey = $this->dedupeKeyFactory->generate(
            $endpoint->getProviderKey(),
            $endpoint->getEndpointKey(),
            $providerEventId,
            $envelope->rawBody,
            $endpoint->getTenantId(),
        );

        // 4. Build inbound delivery. One whose signature failed is kept under
        // a key of its own: the event id is not signed, so a forged request
        // naming a real id took that id's key, and the genuine delivery that
        // followed was ignored as its duplicate.
        $now = new \DateTimeImmutable();
        $id = Uuid7::generate();
        if (!$verificationResult->verified) {
            $dedupeKey = 'rejected:' . $id;
        }
        $delivery = new InboundDelivery(
            id: $id,
            endpointDefinitionId: $endpoint->getId(),
            providerKey: $endpoint->getProviderKey(),
            endpointKey: $endpoint->getEndpointKey(),
            tenantId: $endpoint->getTenantId(),
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

    /**
     * Run a stored delivery through a processor again — for an operator,
     * after the processor was fixed. Only a delivery whose signature was
     * verified when it arrived: the stored bytes are trusted as they were
     * then, not checked again (the timestamp in them has long expired). It
     * runs in the caller's tenant: the command refuses a delivery of another.
     */
    public function replay(InboundDelivery $delivery, WebhookInboundProcessorInterface $processor, bool $force = false): InboundDelivery
    {
        if ($delivery->getSignatureStatus() !== SignatureStatus::Verified) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} was never verified: replaying it would run bytes nobody vouched for.");
        }
        // A worker is on it now: a replay would run it twice at once.
        if ($delivery->getStatus() === InboundStatus::Processing) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} is being processed right now.");
        }
        // Processed means its side effects happened: running them again is a
        // decision, so it takes force.
        if ($delivery->getStatus() === InboundStatus::Processed && !$force) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} was already processed. Replay with force to run it again.");
        }

        $this->inboundService->markProcessing($delivery);
        try {
            $processor->process(new InboundWebhookEnvelope(
                endpointKey: $delivery->getEndpointKey(),
                httpMethod: $delivery->getHttpMethod(),
                requestUri: $delivery->getRequestUri(),
                headers: $delivery->getHeaders() ?? [],
                rawBody: $delivery->getRawBody() ?? '',
                contentType: $delivery->getContentType(),
            ));
            $this->inboundService->markProcessed($delivery);
        } catch (\Throwable $e) {
            $this->inboundService->markFailed($delivery, $e->getMessage());
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
