<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Environment;
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
use Semitexa\Webhooks\Auth\Contract\OwnedClaimStoreInterface;
use Semitexa\Webhooks\Auth\Contract\OwnedClaimUnavailableException;
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

    /** Whether this worker has said that its replay store is not shared. */
    private static bool $warnedNotShared = false;

    /** Whether this worker has said that its replay store cannot record a claim's owner yet. */
    private static bool $warnedNoOwnedClaim = false;

    /**
     * How long a delivery's owner key is kept: twice the claim's window, so a
     * store that forgets keys by itself (Redis) never forgets the owner key
     * while the claim it explains is still there.
     */
    private const REPLAY_OWNER_SECONDS = 1200;

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
            parsedEventType: ($type = $this->extractEventType($envelope, $verificationResult->verified)) === null ? null : mb_strcut($type, 0, 191),
            firstReceivedAt: $now,
            lastReceivedAt: $now,
        );

        // 5. Deduplicate
        $delivery = $this->inboxRepo->insertOrMatchDedupe($delivery);

        if ($delivery->getStatus() === InboundStatus::DuplicateIgnored) {
            return $delivery;
        }

        // 5b. The same signed bytes under another event id. Asked for every
        // verified arrival that is not a duplicate above, a row handed back to
        // be processed again as well as one just stored: the retry of a failed
        // delivery, signed again with a new timestamp, is new signed bytes,
        // and unclaimed they were processed once more under every other event
        // id. The claim names this delivery, so one that meets its own earlier
        // claim (an exact resend of a failure, or a worker that died after
        // claiming) goes on, and one that meets another's does not. Nothing in
        // the row says it: the row's last error is also written by the
        // processor and by the inbox when it takes an abandoned row.
        if ($verificationResult->verified) {
            try {
                $replay = $this->isReplay($endpoint->getEndpointKey(), $envelope, $delivery->getId());
            } catch (\Throwable $e) {
                // The row is stored already. Left Received, every retry would
                // be its duplicate and the delivery lost; Failed, the retry is
                // processed, and it claims as every arrival does (review,
                // semitexa-webhooks#42).
                $this->inboundService->markFailed($delivery, $e->getMessage());

                throw $e;
            }
            if ($replay) {
                $delivery->markDuplicateIgnored();
                $this->inboxRepo->save($delivery);

                return $delivery;
            }
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

    /**
     * The event type a request names: a header, or the "type" of a JSON body.
     * Only a verified body is decoded. A rejected request is anybody's, and its
     * body was decoded whole before its signature counted: a 2 MB body of small
     * objects took 106 MB and killed the worker
     * (tk-webhooks-unsigned-body-decoded-before-signature).
     */
    private function extractEventType(InboundWebhookEnvelope $envelope, bool $verified): ?string
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
        if ($verified && $envelope->contentType !== null && str_contains($envelope->contentType, 'json')) {
            $data = json_decode($envelope->rawBody, true);
            if (is_array($data) && isset($data['type'])) {
                return (string) $data['type'];
            }
        }

        return null;
    }

    /**
     * Whether these exact signed bytes were received at this endpoint within
     * the window by another delivery.
     *
     * A store that records a claim's owner is asked once: the claim is this
     * delivery's, now or from an earlier attempt, or it is another's. One
     * write, so a store error or a dead worker leaves either no claim or a
     * claim that names its winner (review, semitexa-webhooks#43).
     */
    private function isReplay(string $endpointKey, InboundWebhookEnvelope $envelope, string $deliveryId): bool
    {
        if (!isset($this->replayStore)) {
            return false;
        }
        // Only a store the workers share: a per-process one would catch a
        // replay only on the worker that saw the original, and keep every key
        // of unique bytes for the worker's life. The default is shared, so one
        // that is not was bound by a project: said once, outside dev, rather
        // than skipped in silence.
        if (!$this->replayStore->isShared()) {
            self::warnNotShared($this->replayStore->diagnosticName());

            return false;
        }
        $timestamp = '';
        foreach ($envelope->headers as $name => $value) {
            if (strtolower((string) $name) === 'x-webhook-timestamp') {
                $timestamp = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        // One hash of the endpoint and the signed bytes: with its prefix a key
        // is 79 characters at most. The endpoint key may be 191 itself, and the
        // database store's column cuts a longer key, so every request at a long
        // endpoint shared one key and all but the first were taken for replays.
        $signed = hash('sha256', $endpointKey . "\n" . $timestamp . '.' . $envelope->rawBody);

        if ($this->replayStore instanceof OwnedClaimStoreInterface) {
            try {
                return !$this->replayStore->claimFor('inbound-signed:' . $signed, $deliveryId, self::REPLAY_WINDOW_SECONDS);
            } catch (OwnedClaimUnavailableException $e) {
                // The schema is behind the code (deployed, orm:sync not run
                // yet). Nothing was written; failing here would fail every
                // delivery until the sync, so the two keys below stand in.
                self::warnNoOwnedClaim($this->replayStore->diagnosticName(), $e->getMessage());
            }
        }

        return $this->isReplayByTwoKeys($signed, $deliveryId);
    }

    /**
     * The same question for a store that cannot record a claim's owner (a
     * project's own WebhookReplayStoreInterface, or the database store before
     * orm:sync). Two keys, in this order:
     *
     *  1. the owner key, of the bytes and this delivery: whether it was there
     *     already says that an earlier attempt of this delivery came this far
     *     with these bytes;
     *  2. the claim, of the bytes alone: one winner.
     *
     * A lost claim with the owner key already there is taken for this
     * delivery's own, and it goes on; with the owner key new, the bytes are
     * another delivery's.
     *
     * Fail-open on a store error between the two writes: a delivery whose
     * owner key was written and whose claim call then failed is taken, on its
     * retry, for the holder of a claim another delivery made. Two writes
     * cannot close that without dropping a winner whose answer was lost;
     * OwnedClaimStoreInterface does.
     */
    private function isReplayByTwoKeys(string $signed, string $deliveryId): bool
    {
        $ownerIsNew = $this->replayStore->markIfFirstSeen(
            'inbound-owner:' . hash('sha256', $signed . "\n" . $deliveryId),
            self::REPLAY_OWNER_SECONDS,
        );
        $claimed = $this->replayStore->markIfFirstSeen('inbound-signed:' . $signed, self::REPLAY_WINDOW_SECONDS);

        return !$claimed && $ownerIsNew;
    }

    /**
     * Said once per worker, in every environment: unlike a store that is not
     * shared, this one is a deployment step left undone, and it goes away by
     * itself once the step is taken.
     */
    private static function warnNoOwnedClaim(string $store, string $reason): void
    {
        if (self::$warnedNoOwnedClaim) {
            return;
        }
        self::$warnedNoOwnedClaim = true;
        BootDiagnostics::current()->invalidUsage(
            'WebhookReplayStore',
            "inbound webhooks: the replay store ({$store}) cannot record which delivery made a claim ({$reason}) "
            . 'Until it can, the receiver claims with two keys, which a store error between them can open to a replay. Run orm:sync.',
        );
    }

    private static function warnNotShared(string $store): void
    {
        $env = strtolower(trim((string) Environment::getEnvValue('APP_ENV', 'prod')));
        if (self::$warnedNotShared || ($env !== 'prod' && $env !== 'production')) {
            return;
        }
        self::$warnedNotShared = true;
        BootDiagnostics::current()->invalidUsage(
            'WebhookReplayStore',
            "inbound webhooks: the bound replay store ({$store}) is not shared across workers, so the receiver checks no replays: "
            . 'a captured signed request is processed again under every new event id. Bind a shared one: DatabaseWebhookReplayStore '
            . '(the default) or RedisWebhookReplayStore.',
        );
    }
}
