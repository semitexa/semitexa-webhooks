<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Domain\Model\InboundDelivery;
use Semitexa\Webhooks\Enum\InboundStatus;
use Semitexa\Webhooks\Enum\SignatureStatus;

final class InboundDeliveryTest extends TestCase
{
    private function makeDelivery(
        InboundStatus $status = InboundStatus::Received,
        SignatureStatus $signatureStatus = SignatureStatus::Pending,
    ): InboundDelivery {
        $now = new \DateTimeImmutable();
        return new InboundDelivery(
            id: 'test-id',
            endpointDefinitionId: 'ep-def-id',
            providerKey: 'stripe',
            endpointKey: 'stripe-payments',
            tenantId: null,
            providerEventId: 'evt_123',
            dedupeKey: 'provider:stripe:endpoint:stripe-payments:event:evt_123',
            signatureStatus: $signatureStatus,
            status: $status,
            contentType: 'application/json',
            httpMethod: 'POST',
            requestUri: '/webhooks/stripe',
            headers: ['Content-Type' => 'application/json'],
            rawBody: '{"type":"payment_intent.succeeded"}',
            rawBodySha256: hash('sha256', '{"type":"payment_intent.succeeded"}'),
            parsedEventType: 'payment_intent.succeeded',
            firstReceivedAt: $now,
            lastReceivedAt: $now,
        );
    }

    public function testMarkVerifiedTransitionsCorrectly(): void
    {
        $delivery = $this->makeDelivery();

        $delivery->markVerified();

        self::assertSame(InboundStatus::Verified, $delivery->getStatus());
        self::assertSame(SignatureStatus::Verified, $delivery->getSignatureStatus());
    }

    public function testMarkRejectedSignature(): void
    {
        $delivery = $this->makeDelivery();

        $delivery->markRejectedSignature();

        self::assertSame(InboundStatus::RejectedSignature, $delivery->getStatus());
        self::assertSame(SignatureStatus::Rejected, $delivery->getSignatureStatus());
    }

    public function testMarkProcessingSetsTimestamp(): void
    {
        $delivery = $this->makeDelivery(InboundStatus::Verified);

        $delivery->markProcessing();

        self::assertSame(InboundStatus::Processing, $delivery->getStatus());
        self::assertNotNull($delivery->getProcessingStartedAt());
    }

    public function testMarkProcessedSetsTimestamp(): void
    {
        $delivery = $this->makeDelivery(InboundStatus::Processing);

        $delivery->markProcessed();

        self::assertSame(InboundStatus::Processed, $delivery->getStatus());
        self::assertNotNull($delivery->getProcessedAt());
    }

    public function testMarkFailedSetsErrorAndTimestamp(): void
    {
        $delivery = $this->makeDelivery(InboundStatus::Processing);

        $delivery->markFailed('Handler threw an exception');

        self::assertSame(InboundStatus::Failed, $delivery->getStatus());
        self::assertNotNull($delivery->getFailedAt());
        self::assertSame('Handler threw an exception', $delivery->getLastError());
    }

    public function testMarkDuplicateIgnoredIncrementsDuplicateCount(): void
    {
        $delivery = $this->makeDelivery();

        self::assertSame(0, $delivery->getDuplicateCount());

        $delivery->markDuplicateIgnored();

        self::assertSame(InboundStatus::DuplicateIgnored, $delivery->getStatus());
        self::assertSame(1, $delivery->getDuplicateCount());

        $delivery->markDuplicateIgnored();
        self::assertSame(2, $delivery->getDuplicateCount());
    }

    public function testGettersReturnConstructorValues(): void
    {
        $delivery = $this->makeDelivery();

        self::assertSame('test-id', $delivery->getId());
        self::assertSame('ep-def-id', $delivery->getEndpointDefinitionId());
        self::assertSame('stripe', $delivery->getProviderKey());
        self::assertSame('stripe-payments', $delivery->getEndpointKey());
        self::assertNull($delivery->getTenantId());
        self::assertSame('evt_123', $delivery->getProviderEventId());
        self::assertSame('POST', $delivery->getHttpMethod());
        self::assertSame('application/json', $delivery->getContentType());
        self::assertSame('payment_intent.succeeded', $delivery->getParsedEventType());
    }
}
