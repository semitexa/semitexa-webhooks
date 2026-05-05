<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Auth\Attribute\AsAuthHandler;
use Semitexa\Auth\Domain\Contract\AuthHandlerInterface;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthResult;
use Semitexa\Core\Exception\AuthenticationException;
use Semitexa\Core\Request;
use Semitexa\Core\Tenant\Layer\OrganizationLayer;
use Semitexa\Core\Tenant\TenantContextStoreInterface;
use Semitexa\Webhooks\Auth\Attribute\AsWebhookReceiver;
use Semitexa\Webhooks\Domain\Contract\WebhookSignatureVerifierInterface;
use Semitexa\Webhooks\Domain\Model\WebhookVerificationInput;

/**
 * AuthHandler that authenticates HMAC-signed webhook requests.
 *
 * Runs at priority 5 — BEFORE AuthDemoStubAuthHandler (priority 10) and
 * BEFORE semitexa-api's MachineAuthHandler (priority 50). This ordering
 * matters: AuthDemoStubAuthHandler authenticates on a header alone, and
 * without webhook precedence a webhook payload sent with `X-Auth-Demo-Token:
 * service:foo` would be authenticated as a service principal AND bypass
 * signature verification entirely. By running first AND throwing
 * AuthenticationException on a webhook payload with a bad/missing signature,
 * this handler short-circuits the chain so no other handler can satisfy a
 * webhook receiver via a stray header.
 *
 * Scoping rule:
 *   - Payload has no #[AsWebhookReceiver] → return null, let chain continue.
 *     Non-webhook routes are unaffected.
 *   - Payload IS a webhook receiver →
 *       valid signature  → AuthResult::successAsService(WebhookPrincipal)
 *       missing/bad/expired signature → throw AuthenticationException
 *
 * Throwing (instead of returning failed) is intentional: AuthBootstrapper's
 * Mandatory mode propagates the exception, which RouteExecutor maps via the
 * ExceptionMapper to a controlled 401. If we returned failed instead, the
 * bootstrapper would continue trying other handlers — and another handler
 * could "succeed" the webhook route with the wrong domain of credentials.
 *
 * Replay/idempotency are NOT this handler's job. Signature verification proves
 * authenticity; replay protection is a domain concern that belongs in the
 * pipeline or handler layer (where it can return 409 instead of 401).
 */
#[AsAuthHandler(priority: 5)]
final class WebhookAuthHandler implements AuthHandlerInterface
{
    public const HEADER_EVENT_ID = 'X-Webhook-Event-Id';

    #[InjectAsReadonly]
    protected WebhookSignatureVerifierInterface $verifier;

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenantStore;

    public function handle(object $payload): ?AuthResult
    {
        $reflection = new \ReflectionClass($payload);
        $attrs = $reflection->getAttributes(AsWebhookReceiver::class);
        if ($attrs === []) {
            // Not a webhook payload — leave for the next handler.
            return null;
        }

        /** @var AsWebhookReceiver $attr */
        $attr = $attrs[0]->newInstance();

        // Cycle-11: prefer the framework's CurrentRequestStore so any
        // webhook payload works without the setHttpRequest convention.
        $request = method_exists($payload, 'getHttpRequest') ? $payload->getHttpRequest() : null;
        if (!$request instanceof Request) {
            $request = \Semitexa\Core\Lifecycle\CurrentRequestStore::get();
        }
        if (!$request instanceof Request) {
            // Pre-hydration gate populates CurrentRequestStore + setHttpRequest;
            // reaching here means neither path delivered the request, which is
            // a hard misconfiguration.
            throw new AuthenticationException('Webhook auth handler could not access HTTP request');
        }

        if (!isset($this->verifier)) {
            // Container did not supply a verifier — fail closed. A misconfigured
            // deployment must NOT be authenticated as service.
            throw new AuthenticationException('Webhook signature verifier unavailable');
        }

        $rawBody = $request->getContent() ?? '';
        $headers = $request->headers;
        $tenantId = $this->resolveTenantId();

        $result = $this->verifier->verify(new WebhookVerificationInput(
            headers: $headers,
            rawBody: $rawBody,
            secretRef: $attr->secretRef,
            verificationMode: $attr->verificationMode,
            tenantId: $tenantId,
        ));

        if (!$result->verified) {
            throw new AuthenticationException('Webhook signature rejected: ' . $result->reason);
        }

        // Prefer the explicit receiver name from #[AsWebhookReceiver] when
        // set — that's what flows into ServiceCapabilityProviderInterface
        // lookups via WebhookPrincipal::getId(). Falls back to the payload
        // class short name for receivers that did not opt into per-receiver
        // service-capability authorization.
        return AuthResult::successAsService(new WebhookPrincipal(
            endpointKey: $attr->name ?? $reflection->getShortName(),
            eventId: $request->getHeader(self::HEADER_EVENT_ID),
            tenantId: $tenantId,
        ));
    }

    /**
     * Read the active tenant id (organization layer) from the framework's
     * tenancy phase output. Returns null when no tenancy bootstrapper is
     * wired, or when this request did not match any tenant resolution
     * strategy. The tenancy phase always runs BEFORE the auth phase (see
     * Application::handleRequest), so by the time this handler runs the
     * tenant is already either resolved or definitively absent.
     */
    private function resolveTenantId(): ?string
    {
        if (!isset($this->tenantStore)) {
            return null;
        }
        $context = $this->tenantStore->tryGet();
        if ($context === null) {
            return null;
        }
        $org = new OrganizationLayer();
        if (!$context->hasLayer($org)) {
            return null;
        }
        $value = $context->getLayer($org);
        if ($value === null) {
            return null;
        }
        $raw = $value->rawValue();
        return $raw !== '' ? $raw : null;
    }
}
