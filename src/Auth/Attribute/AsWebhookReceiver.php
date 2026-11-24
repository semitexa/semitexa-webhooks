<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth\Attribute;

use Attribute;

/**
 * Marks a payload as a webhook receiver.
 *
 * Pairs with #[AsServicePayload]: the access-class attribute makes the route
 * service-domain (so user/session auth can never satisfy it); this attribute
 * opts the route into the WebhookAuthHandler signature verification chain.
 *
 * Without this attribute, no other AuthHandler in the project will know how to
 * authenticate a signed webhook request — so a pure #[AsServicePayload] route
 * still works for non-webhook machine auth (machine bearer tokens, etc.) and
 * stays separated from this signature-driven path.
 *
 * Constructor args:
 *   secretRef            — string accepted by the WebhookSignatureVerifier
 *                          ("env:VAR_NAME" reads an env var; bare value is a
 *                          literal, dev/test only)
 *   replayWindowSeconds  — carried, not read: nothing passes it on, and
 *                          WebhookVerificationInput has no such field. The
 *                          signature window is the verifier's fixed 300
 *                          seconds either side, and the inbox claims signed
 *                          bytes for that window plus a clock margin
 *                          (InboundWebhookReceiver::REPLAY_WINDOW_SECONDS).
 *   verificationMode     — accepted by the existing verifier (null = no
 *                          verification; any non-empty string = verify). The
 *                          default 'hmac-sha256' matches the only verifier
 *                          implementation shipped today; future verifier
 *                          variants would dispatch on this value.
 *   name                 — STABLE receiver identifier carried into
 *                          WebhookPrincipal::endpointKey (and therefore into
 *                          ServiceCapabilityProviderInterface lookups). When
 *                          null, the WebhookAuthHandler falls back to the
 *                          payload class short name. Set this for
 *                          capability-bound routes so the service identity
 *                          is not coupled to the PHP class name.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsWebhookReceiver
{
    public function __construct(
        public readonly string $secretRef,
        public readonly int $replayWindowSeconds = 300,
        public readonly string $verificationMode = 'hmac-sha256',
        public readonly ?string $name = null,
    ) {}
}
