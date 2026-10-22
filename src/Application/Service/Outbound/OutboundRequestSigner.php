<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Webhooks\Auth\Contract\WebhookSecretResolverInterface;
use Semitexa\Webhooks\Auth\EnvWebhookSecretResolver;

#[AsService]
final class OutboundRequestSigner
{
    /**
     * The resolver the receivers use. Read here by hand, a `vault:` reference
     * a project's resolver knows was taken as the HMAC key itself — a key
     * anyone reading the endpoint row could sign with.
     */
    #[InjectAsReadonly(optional: true)]
    protected WebhookSecretResolverInterface $secretResolver;

    /**
     * @param string|null $tenantId the endpoint's tenant, for a resolver that keeps secrets per tenant
     * @throws \RuntimeException when the secret is missing or empty
     */
    public function sign(string $body, string $secretRef, string $algorithm = 'sha256', ?string $tenantId = null): array
    {
        $secret = (isset($this->secretResolver) ? $this->secretResolver : new EnvWebhookSecretResolver())->resolve($secretRef, $tenantId);
        // A missing secret used to send the webhook unsigned, with no error;
        // the receiver then refused every delivery, or worse, accepted them.
        if ($secret === null || $secret === '') {
            throw new \RuntimeException("The signing secret {$secretRef} is missing or empty: the webhook was not sent.");
        }

        $timestamp = (string) time();
        $signingPayload = $timestamp . '.' . $body;
        $signature = hash_hmac($algorithm, $signingPayload, $secret);

        return [
            'X-Webhook-Signature' => "{$algorithm}={$signature}",
            'X-Webhook-Timestamp' => $timestamp,
        ];
    }
}
