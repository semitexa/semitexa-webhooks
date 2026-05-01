<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Application\Service\WebhookEndpointRegistry;

final class WebhookEndpointRegistryTest extends TestCase
{
    private function makeDefinition(string $key, WebhookDirection $direction = WebhookDirection::Inbound): WebhookEndpointDefinition
    {
        $now = new \DateTimeImmutable();
        return new WebhookEndpointDefinition(
            id: 'id-' . $key,
            endpointKey: $key,
            direction: $direction,
            providerKey: 'test',
            enabled: true,
            tenantId: null,
            verificationMode: 'hmac_sha256',
            signingMode: null,
            secretRef: 'env:SECRET',
            targetUrl: null,
            timeoutSeconds: 10,
            maxAttempts: 5,
            initialBackoffSeconds: 30,
            maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null,
            handlerClass: null,
            defaultHeaders: null,
            metadata: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function testRegisterAndFindByKey(): void
    {
        $registry = new WebhookEndpointRegistry();
        $def = $this->makeDefinition('stripe-payments');

        $registry->register($def);

        self::assertSame($def, $registry->findByKey('stripe-payments'));
    }

    public function testFindByKeyReturnsNullForUnknown(): void
    {
        $registry = new WebhookEndpointRegistry();

        self::assertNull($registry->findByKey('nonexistent'));
    }

    public function testAllReturnsAllRegistered(): void
    {
        $registry = new WebhookEndpointRegistry();
        $registry->register($this->makeDefinition('ep-1'));
        $registry->register($this->makeDefinition('ep-2'));

        $all = $registry->all();

        self::assertCount(2, $all);
    }

    public function testRegisterOverwritesDuplicate(): void
    {
        $registry = new WebhookEndpointRegistry();
        $registry->register($this->makeDefinition('ep-1'));
        $registry->register($this->makeDefinition('ep-1'));

        self::assertCount(1, $registry->all());
    }
}
