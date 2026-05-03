<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Outbound\OutboundRequestSigner;

final class OutboundRequestSignerTest extends TestCase
{
    private OutboundRequestSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new OutboundRequestSigner();
    }

    public function testSignReturnsSignatureAndTimestampHeaders(): void
    {
        $body = '{"event":"order.created","id":42}';
        $secret = 'signing-secret';

        $headers = $this->signer->sign($body, $secret);

        self::assertArrayHasKey('X-Webhook-Signature', $headers);
        self::assertArrayHasKey('X-Webhook-Timestamp', $headers);
        self::assertStringStartsWith('sha256=', $headers['X-Webhook-Signature']);
    }

    public function testSignatureIsVerifiable(): void
    {
        $body = '{"event":"test"}';
        $secret = 'my-secret';

        $headers = $this->signer->sign($body, $secret);

        $timestamp = $headers['X-Webhook-Timestamp'];
        $signature = str_replace('sha256=', '', $headers['X-Webhook-Signature']);
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        self::assertSame($expected, $signature);
    }

    public function testReturnsEmptyArrayWhenSecretNotResolvable(): void
    {
        $headers = $this->signer->sign('{}', 'env:NONEXISTENT_VAR_' . bin2hex(random_bytes(8)));

        self::assertSame([], $headers);
    }

    public function testResolvesEnvSecret(): void
    {
        $envVar = 'TEST_SIGN_SECRET_' . strtoupper(bin2hex(random_bytes(4)));
        $_ENV[$envVar] = 'env-secret-value';

        try {
            $headers = $this->signer->sign('{}', "env:{$envVar}");

            self::assertArrayHasKey('X-Webhook-Signature', $headers);
        } finally {
            unset($_ENV[$envVar]);
        }
    }

    public function testDifferentBodiesProduceDifferentSignatures(): void
    {
        $secret = 'test-secret';
        $h1 = $this->signer->sign('{"a":1}', $secret);
        $h2 = $this->signer->sign('{"a":2}', $secret);

        self::assertNotSame($h1['X-Webhook-Signature'], $h2['X-Webhook-Signature']);
    }
}
