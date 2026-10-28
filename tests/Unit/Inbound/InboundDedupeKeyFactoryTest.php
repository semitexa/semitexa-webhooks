<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Inbound;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Inbound\InboundDedupeKeyFactory;

final class InboundDedupeKeyFactoryTest extends TestCase
{
    private InboundDedupeKeyFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new InboundDedupeKeyFactory();
    }

    public function testGeneratesKeyWithProviderEventId(): void
    {
        $key = $this->factory->generate('stripe', 'stripe-payments', 'evt_123', '{}');

        self::assertSame('provider:stripe:endpoint:stripe-payments:event:' . hash('sha256', "evt_123\0" . hash('sha256', '{}')), $key);
    }

    /** The id is not signed: another body under the same id is another delivery, the same body the same one. */
    public function testTheEventIdIsBoundToTheBody(): void
    {
        $genuine = $this->factory->generate('stripe', 'p', 'evt_9', '{"refund":42}');

        self::assertSame($genuine, $this->factory->generate('stripe', 'p', 'evt_9', '{"refund":42}'));
        self::assertNotSame($genuine, $this->factory->generate('stripe', 'p', 'evt_9', '{"captured":1}'));
        self::assertLessThanOrEqual(255, strlen($this->factory->generate('stripe', 'p', str_repeat('x', 1000), '{}')));
    }

    public function testGeneratesKeyWithBodyHashWhenNoEventId(): void
    {
        $body = '{"amount":100}';
        $hash = hash('sha256', $body);

        $key = $this->factory->generate('custom', 'my-endpoint', null, $body);

        self::assertSame("provider:custom:endpoint:my-endpoint:hash:{$hash}", $key);
    }

    public function testGeneratesKeyWithBodyHashWhenEventIdIsEmpty(): void
    {
        $body = '{"amount":100}';
        $hash = hash('sha256', $body);

        $key = $this->factory->generate('custom', 'my-endpoint', '', $body);

        self::assertSame("provider:custom:endpoint:my-endpoint:hash:{$hash}", $key);
    }

    public function testPrefixesTenantIdWhenProvided(): void
    {
        $key = $this->factory->generate('github', 'gh-hooks', 'delivery_abc', '{}', 'tenant-42');

        self::assertSame('tenant:tenant-42:provider:github:endpoint:gh-hooks:event:' . hash('sha256', "delivery_abc\0" . hash('sha256', '{}')), $key);
    }

    public function testTenantPrefixWithBodyHash(): void
    {
        $body = '{"push":true}';
        $hash = hash('sha256', $body);

        $key = $this->factory->generate('github', 'gh-hooks', null, $body, 'tenant-42');

        self::assertSame("tenant:tenant-42:provider:github:endpoint:gh-hooks:hash:{$hash}", $key);
    }

    public function testSameInputsProduceSameKey(): void
    {
        $key1 = $this->factory->generate('stripe', 'payments', 'evt_1', '{}');
        $key2 = $this->factory->generate('stripe', 'payments', 'evt_1', '{}');

        self::assertSame($key1, $key2);
    }

    public function testDifferentBodiesProduceDifferentHashKeys(): void
    {
        $key1 = $this->factory->generate('custom', 'ep', null, '{"a":1}');
        $key2 = $this->factory->generate('custom', 'ep', null, '{"a":2}');

        self::assertNotSame($key1, $key2);
    }
}
