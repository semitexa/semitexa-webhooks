<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\AsService;

#[AsService]
final class OutboundRequestSigner
{
    /** @throws \RuntimeException when the secret is missing or empty */
    public function sign(string $body, string $secretRef, string $algorithm = 'sha256'): array
    {
        $secret = $this->resolveSecret($secretRef);
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

    private function resolveSecret(string $secretRef): ?string
    {
        if (str_starts_with($secretRef, 'env:')) {
            $envVar = substr($secretRef, 4);
            $value = $_ENV[$envVar] ?? getenv($envVar);
            return $value !== false ? (string) $value : null;
        }

        return $secretRef;
    }
}
