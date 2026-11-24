<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Webhooks\Application\Service\Inbound\HmacSha256SignatureVerifier;
use Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookReceiver;
use Semitexa\Webhooks\Application\Service\Inbound\InboundWebhookService;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\KeepingInbox;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\RecordingOwnedReplayStore;
use Semitexa\Webhooks\Tests\Unit\Inbound\Fixtures\RecordingReplayStore;

/**
 * The claim of a delivery's signed bytes lived exactly as long as the
 * signature verifies, 300 s either way: 600 s by the clock that expires it.
 * A worker whose clock is N seconds behind that one accepted the bytes for N
 * seconds after the claim was gone, and a copy under another event id was
 * processed (review, semitexa-docs#185). The claim is kept a clock margin
 * longer (tk-webhooks-replay-claim-has-no-clock-margin).
 */
final class InboundReceiverReplayClaimTtlTest extends TestCase
{
    private const SECRET = 'partner-secret';

    /** The least a claim must cover for clocks a minute apart. */
    private const CLOCK_MARGIN_SECONDS = 60;

    private bool $warnedNoOwnedClaimBefore = false;

    private ?BootDiagnostics $diagnosticsBefore = null;

    // The fallback's warned flag and the diagnostics collector are process
    // state: kept as they were and given back.
    protected function setUp(): void
    {
        $current = self::collector()->getValue();
        $this->diagnosticsBefore = $current instanceof BootDiagnostics ? $current : null;
        $this->warnedNoOwnedClaimBefore = (bool) self::warnedNoOwnedClaim()->getValue();
    }

    protected function tearDown(): void
    {
        self::warnedNoOwnedClaim()->setValue(null, $this->warnedNoOwnedClaimBefore);
        self::collector()->setValue(null, $this->diagnosticsBefore);
    }

    #[Test]
    public function the_owned_claim_outlives_the_signature_window_by_the_clock_margin(): void
    {
        $store = new RecordingOwnedReplayStore();

        self::assertSame(InboundStatus::Verified, $this->receiver($store)->receive($this->envelope())->getStatus());

        self::assertCount(1, $store->claims, 'one claim, with its owner');
        self::assertStringStartsWith('inbound-signed:', $store->claims[0]['key']);
        self::assertGreaterThan(
            self::signatureWindow() + self::CLOCK_MARGIN_SECONDS,
            $store->claims[0]['ttl'],
            'the claim covers the window the bytes verify in, clocks that disagree, and the boundary second: the verifier accepts a drift of exactly 300 s and the database cleanup deletes at expires_at',
        );
    }

    #[Test]
    public function the_two_key_fallback_keeps_the_same_margin_and_its_owner_key_outlives_the_claim(): void
    {
        BootDiagnostics::begin();
        $store = new RecordingReplayStore();

        self::assertSame(InboundStatus::Verified, $this->receiver($store)->receive($this->envelope())->getStatus());

        $ttl = [];
        foreach ($store->claims as $claim) {
            $ttl[strstr($claim['key'], ':', true)] = $claim['ttl'];
        }
        self::assertSame(['inbound-owner', 'inbound-signed'], array_keys($ttl), 'the owner key, then the claim');
        self::assertGreaterThan(
            self::signatureWindow() + self::CLOCK_MARGIN_SECONDS,
            $ttl['inbound-signed'],
            'the claim covers the window the bytes verify in, clocks that disagree, and the boundary second',
        );
        self::assertGreaterThan($ttl['inbound-signed'], $ttl['inbound-owner'], 'the owner key is there for as long as the claim it explains');
    }

    /** The receiver's copy of the verifier's drift is kept by hand: changed in one place only, the claim is too short again. */
    #[Test]
    public function the_receiver_counts_the_window_by_the_verifiers_drift(): void
    {
        self::assertSame(
            (new \ReflectionClassConstant(HmacSha256SignatureVerifier::class, 'MAX_TIMESTAMP_DRIFT_SECONDS'))->getValue(),
            (new \ReflectionClassConstant(InboundWebhookReceiver::class, 'SIGNATURE_DRIFT_SECONDS'))->getValue(),
        );
    }

    /** How long a signed request verifies: the verifier's drift, before and after the worker's clock. */
    private static function signatureWindow(): int
    {
        $drift = (new \ReflectionClassConstant(HmacSha256SignatureVerifier::class, 'MAX_TIMESTAMP_DRIFT_SECONDS'))->getValue();
        self::assertIsInt($drift);

        return 2 * $drift;
    }

    private function envelope(): InboundWebhookEnvelope
    {
        $ts = (string) time();
        $body = '{"refund":42}';

        return new InboundWebhookEnvelope(
            endpointKey: 'partner',
            httpMethod: 'POST',
            requestUri: '/webhooks/partner',
            headers: [
                'X-Webhook-Event-Id' => 'evt-1',
                'X-Webhook-Timestamp' => $ts,
                'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, self::SECRET),
            ],
            rawBody: $body,
            contentType: 'application/json',
        );
    }

    private function receiver(WebhookReplayStoreInterface $replays): InboundWebhookReceiver
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-in', endpointKey: 'partner', direction: WebhookDirection::Inbound, providerKey: 'partner',
            enabled: true, tenantId: null, verificationMode: 'hmac-sha256', signingMode: null, secretRef: self::SECRET,
            targetUrl: null, timeoutSeconds: 5, maxAttempts: 5, initialBackoffSeconds: 30, maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null, handlerClass: null, defaultHeaders: null, metadata: null,
            createdAt: new \DateTimeImmutable(), updatedAt: new \DateTimeImmutable(),
        ));
        $inbox = new KeepingInbox();
        $service = new InboundWebhookService();
        (new \ReflectionProperty(InboundWebhookService::class, 'inboundRepo'))->setValue($service, $inbox);
        (new \ReflectionProperty(InboundWebhookService::class, 'attemptRepo'))->setValue($service, $this->createStub(WebhookAttemptRepositoryInterface::class));

        $receiver = new InboundWebhookReceiver();
        foreach ([
            'endpointRepo' => $endpoints,
            'verifier' => new HmacSha256SignatureVerifier(),
            'inboxRepo' => $inbox,
            'inboundService' => $service,
            'dedupeKeyFactory' => new InboundDedupeKeyFactory(),
            'replayStore' => $replays,
        ] as $property => $value) {
            (new \ReflectionProperty(InboundWebhookReceiver::class, $property))->setValue($receiver, $value);
        }

        return $receiver;
    }

    private static function warnedNoOwnedClaim(): \ReflectionProperty
    {
        return new \ReflectionProperty(InboundWebhookReceiver::class, 'warnedNoOwnedClaim');
    }

    private static function collector(): \ReflectionProperty
    {
        return new \ReflectionProperty(BootDiagnostics::class, 'current');
    }
}
