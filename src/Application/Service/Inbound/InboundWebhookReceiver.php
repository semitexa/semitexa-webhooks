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
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

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

    /**
     * Remembers the signed bytes (timestamp and body) of what was received:
     * re-sent with new event ids, one captured request was processed once
     * per id. Optional only for a receiver built by hand.
     */
    #[InjectAsReadonly(optional: true)]
    protected WebhookReplayStoreInterface $replayStore;

    private const REJECTED_BODY_BYTES = 4096;

    /** The signature's ±300 s window, both ways: a request is valid that long. */
    private const REPLAY_WINDOW_SECONDS = 600;

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

        // An outbound endpoint's row (verificationMode null) received a POST
        // with no signature at all as verified, and processed it: the key in
        // a URL was the only lock. Only an inbound endpoint that verifies
        // receives — as #[AsWebhookReceiver] already insists.
        if ($endpoint->getDirection() !== WebhookDirection::Inbound) {
            throw new \RuntimeException("Webhook endpoint does not receive: {$envelope->endpointKey}");
        }
        if (($endpoint->getVerificationMode() ?? '') === '') {
            throw new \RuntimeException("Webhook endpoint {$envelope->endpointKey} names no verificationMode: it would accept unsigned requests.");
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
        // Header values, cut to their columns: under MySQL strict mode a long
        // one failed the insert with a 500. The dedupe key hashes the full id.
        $providerEventIdFull = $this->extractProviderEventId($envelope);
        $providerEventId = $providerEventIdFull === null ? null : mb_strcut($providerEventIdFull, 0, 255);
        $rawBodySha256 = hash('sha256', $envelope->rawBody);
        $dedupeKey = $this->dedupeKeyFactory->generate(
            $endpoint->getProviderKey(),
            $endpoint->getEndpointKey(),
            $providerEventIdFull,
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
            // A rejected request is anybody's: kept whole for the retention
            // window, unsigned bodies filled the inbox. Its hash and a head
            // are enough to see what was tried.
            rawBody: $verificationResult->verified ? $envelope->rawBody : mb_strcut($envelope->rawBody, 0, self::REJECTED_BODY_BYTES),
            rawBodySha256: $rawBodySha256,
            parsedEventType: ($type = $this->extractEventType($envelope)) === null ? null : mb_strcut($type, 0, 191),
            firstReceivedAt: $now,
            lastReceivedAt: $now,
        );

        // 5. Deduplicate
        $delivery = $this->inboxRepo->insertOrMatchDedupe($delivery);

        if ($delivery->getStatus() === InboundStatus::DuplicateIgnored) {
            return $delivery;
        }

        // 5b. The same signed bytes under another event id. Asked only for a
        // row just stored: an exact repeat under the same id matches its
        // original above and is handled there (a failed one is processed
        // again), and a request that died before this point claimed nothing.
        if ($verificationResult->verified && $delivery->getId() === $id
            && $this->isReplay($endpoint->getEndpointKey(), $envelope)) {
            $delivery->markDuplicateIgnored();
            $this->inboxRepo->save($delivery);

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
        // A copy of another delivery, never meant to run: running it is
        // running that delivery twice.
        if ($delivery->getStatus() === InboundStatus::DuplicateIgnored) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} is a duplicate of another; replay that one.");
        }
        // A worker is on it now: a replay would run it twice at once. With
        // force, one stuck there for 15 minutes (its worker died) is taken.
        if ($delivery->getStatus() === InboundStatus::Processing && !$force) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} is being processed right now; with force, one stuck for 15 minutes is taken over.");
        }
        // Processed means its side effects happened: running them again is a
        // decision, so it takes force.
        if ($delivery->getStatus() === InboundStatus::Processed && !$force) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} was already processed. Replay with force to run it again.");
        }

        // The checks above answer the caller; this decides: a second replay
        // started at the same moment passed them too.
        if (!$this->inboxRepo->claimForReplay($delivery->getId(), $force)) {
            throw new \RuntimeException("Inbound delivery {$delivery->getId()} is being worked on (a provider retry, a replay or a worker) or has not been still for 15 minutes; try again later.");
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

    /** Whether these exact signed bytes were received at this endpoint within the window. */
    private function isReplay(string $endpointKey, InboundWebhookEnvelope $envelope): bool
    {
        // Only a store the workers share: the default in-memory one is per
        // process, would catch a replay only on the worker that saw the
        // original, and kept every key of unique bytes for the worker's life.
        if (!isset($this->replayStore) || !$this->replayStore->isShared()) {
            return false;
        }
        $timestamp = '';
        foreach ($envelope->headers as $name => $value) {
            if (strtolower((string) $name) === 'x-webhook-timestamp') {
                $timestamp = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return !$this->replayStore->markIfFirstSeen(
            'inbound-signed:' . $endpointKey . ':' . hash('sha256', $timestamp . '.' . $envelope->rawBody),
            self::REPLAY_WINDOW_SECONDS,
        );
    }
}
