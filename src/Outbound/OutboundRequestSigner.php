<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Outbound;

final class OutboundRequestSigner
{
    public function sign(string $body, string $secretRef, string $algorithm = 'sha256'): array
    {
        $secret = $this->resolveSecret($secretRef);
        if ($secret === null) {
            return [];
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
