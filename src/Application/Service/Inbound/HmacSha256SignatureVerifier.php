<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Inbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Webhooks\Auth\Contract\WebhookSecretResolverInterface;
use Semitexa\Webhooks\Auth\EnvWebhookSecretResolver;
use Semitexa\Webhooks\Domain\Contract\WebhookSignatureVerifierInterface;
use Semitexa\Webhooks\Domain\Model\VerificationResult;
use Semitexa\Webhooks\Domain\Model\WebhookVerificationInput;

#[SatisfiesServiceContract(of: WebhookSignatureVerifierInterface::class)]
final class HmacSha256SignatureVerifier implements WebhookSignatureVerifierInterface
{
    private const SIGNATURE_HEADER = 'X-Webhook-Signature';
    private const TIMESTAMP_HEADER = 'X-Webhook-Timestamp';
    private const MAX_TIMESTAMP_DRIFT_SECONDS = 300;

    #[InjectAsReadonly]
    protected WebhookSecretResolverInterface $secretResolver;

    public function verify(WebhookVerificationInput $input): VerificationResult
    {
        if ($input->verificationMode === null || $input->verificationMode === '') {
            return VerificationResult::notRequired();
        }

        if ($input->secretRef === null || $input->secretRef === '') {
            return VerificationResult::failure('No secret configured for verification');
        }

        $secret = $this->resolver()->resolve($input->secretRef, $input->tenantId);
        if ($secret === null) {
            return VerificationResult::failure('Failed to resolve secret from ref: ' . $input->secretRef);
        }

        $signatureHeader = $this->getHeader($input->headers, self::SIGNATURE_HEADER);
        if ($signatureHeader === null) {
            return VerificationResult::failure('Missing signature header: ' . self::SIGNATURE_HEADER);
        }

        // Optional timestamp validation
        $timestampHeader = $this->getHeader($input->headers, self::TIMESTAMP_HEADER);
        if ($timestampHeader !== null) {
            $timestamp = (int) $timestampHeader;
            $drift = abs(time() - $timestamp);
            if ($drift > self::MAX_TIMESTAMP_DRIFT_SECONDS) {
                return VerificationResult::failure(
                    sprintf('Timestamp drift %d seconds exceeds maximum %d', $drift, self::MAX_TIMESTAMP_DRIFT_SECONDS)
                );
            }
            $signingPayload = $timestampHeader . '.' . $input->rawBody;
        } else {
            $signingPayload = $input->rawBody;
        }

        $expectedSignature = hash_hmac('sha256', $signingPayload, $secret);

        if (!hash_equals($expectedSignature, $this->normalizeSignature($signatureHeader))) {
            return VerificationResult::failure('Signature mismatch');
        }

        return VerificationResult::success();
    }

    /**
     * Returns the wired {@see WebhookSecretResolverInterface}, falling back
     * to {@see EnvWebhookSecretResolver} in setups where DI was bypassed
     * (legacy unit-test constructions, tooling that pokes the verifier
     * directly). Production paths always inject the resolver via DI.
     */
    private function resolver(): WebhookSecretResolverInterface
    {
        if (!isset($this->secretResolver)) {
            $this->secretResolver = new EnvWebhookSecretResolver();
        }
        return $this->secretResolver;
    }

    private function getHeader(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $lower) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }
        return null;
    }

    private function normalizeSignature(string $signature): string
    {
        // Strip common prefixes like "sha256=" or "v1="
        if (str_contains($signature, '=')) {
            $parts = explode('=', $signature, 2);
            if (strlen($parts[0]) <= 10) {
                return $parts[1];
            }
        }
        return $signature;
    }
}
