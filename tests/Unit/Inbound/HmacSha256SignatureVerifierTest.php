<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Domain\Model\WebhookVerificationInput;
use Semitexa\Webhooks\Inbound\HmacSha256SignatureVerifier;

final class HmacSha256SignatureVerifierTest extends TestCase
{
    private HmacSha256SignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new HmacSha256SignatureVerifier();
    }

    public function testVerificationNotRequiredWhenModeIsNull(): void
    {
        $input = new WebhookVerificationInput(
            headers: [],
            rawBody: '{"event":"test"}',
            secretRef: 'some-secret',
            verificationMode: null,
        );

        $result = $this->verifier->verify($input);

        self::assertTrue($result->verified);
        self::assertSame('Verification not required', $result->reason);
    }

    public function testVerificationNotRequiredWhenModeIsEmpty(): void
    {
        $input = new WebhookVerificationInput(
            headers: [],
            rawBody: '{"event":"test"}',
            secretRef: 'some-secret',
            verificationMode: '',
        );

        $result = $this->verifier->verify($input);

        self::assertTrue($result->verified);
    }

    public function testFailsWhenNoSecretConfigured(): void
    {
        $input = new WebhookVerificationInput(
            headers: ['X-Webhook-Signature' => 'abc'],
            rawBody: '{"event":"test"}',
            secretRef: null,
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertFalse($result->verified);
        self::assertStringContainsString('No secret configured', $result->reason);
    }

    public function testFailsWhenSignatureHeaderMissing(): void
    {
        $input = new WebhookVerificationInput(
            headers: [],
            rawBody: '{"event":"test"}',
            secretRef: 'my-secret',
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertFalse($result->verified);
        self::assertStringContainsString('Missing signature header', $result->reason);
    }

    public function testValidSignatureWithoutTimestamp(): void
    {
        $secret = 'test-secret-key';
        $body = '{"event":"payment.completed"}';
        $signature = hash_hmac('sha256', $body, $secret);

        $input = new WebhookVerificationInput(
            headers: ['X-Webhook-Signature' => $signature],
            rawBody: $body,
            secretRef: $secret,
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertTrue($result->verified);
        self::assertSame('Signature verified', $result->reason);
    }

    public function testValidSignatureWithTimestamp(): void
    {
        $secret = 'test-secret-key';
        $body = '{"event":"payment.completed"}';
        $timestamp = (string) time();
        $signingPayload = $timestamp . '.' . $body;
        $signature = hash_hmac('sha256', $signingPayload, $secret);

        $input = new WebhookVerificationInput(
            headers: [
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Timestamp' => $timestamp,
            ],
            rawBody: $body,
            secretRef: $secret,
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertTrue($result->verified);
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $secret = 'test-secret-key';
        $body = '{"event":"test"}';
        $timestamp = (string) (time() - 600); // 10 minutes ago
        $signingPayload = $timestamp . '.' . $body;
        $signature = hash_hmac('sha256', $signingPayload, $secret);

        $input = new WebhookVerificationInput(
            headers: [
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Timestamp' => $timestamp,
            ],
            rawBody: $body,
            secretRef: $secret,
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertFalse($result->verified);
        self::assertStringContainsString('Timestamp drift', $result->reason);
    }

    public function testRejectsInvalidSignature(): void
    {
        $input = new WebhookVerificationInput(
            headers: ['X-Webhook-Signature' => 'definitely-wrong'],
            rawBody: '{"event":"test"}',
            secretRef: 'my-secret',
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertFalse($result->verified);
        self::assertSame('Signature mismatch', $result->reason);
    }

    public function testNormalizesPrefixedSignature(): void
    {
        $secret = 'test-secret-key';
        $body = '{"event":"test"}';
        $signature = hash_hmac('sha256', $body, $secret);

        $input = new WebhookVerificationInput(
            headers: ['X-Webhook-Signature' => "sha256={$signature}"],
            rawBody: $body,
            secretRef: $secret,
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertTrue($result->verified);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $secret = 'test-secret-key';
        $body = '{"event":"test"}';
        $signature = hash_hmac('sha256', $body, $secret);

        $input = new WebhookVerificationInput(
            headers: ['x-webhook-signature' => $signature],
            rawBody: $body,
            secretRef: $secret,
            verificationMode: 'hmac_sha256',
        );

        $result = $this->verifier->verify($input);

        self::assertTrue($result->verified);
    }

    public function testResolvesEnvSecret(): void
    {
        $secret = 'env-resolved-secret-' . bin2hex(random_bytes(4));
        $envVar = 'TEST_WEBHOOK_SECRET_' . strtoupper(bin2hex(random_bytes(4)));
        $_ENV[$envVar] = $secret;

        try {
            $body = '{"event":"test"}';
            $signature = hash_hmac('sha256', $body, $secret);

            $input = new WebhookVerificationInput(
                headers: ['X-Webhook-Signature' => $signature],
                rawBody: $body,
                secretRef: "env:{$envVar}",
                verificationMode: 'hmac_sha256',
            );

            $result = $this->verifier->verify($input);

            self::assertTrue($result->verified);
        } finally {
            unset($_ENV[$envVar]);
        }
    }
}
