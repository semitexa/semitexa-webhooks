<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Enum\OutboundStatus;

final class OutboundDeliveryTest extends TestCase
{
    private function makeDelivery(
        OutboundStatus $status = OutboundStatus::Pending,
        int $attemptCount = 0,
        int $maxAttempts = 5,
    ): OutboundDelivery {
        return new OutboundDelivery(
            id: 'outbox-1',
            endpointDefinitionId: 'ep-def-id',
            endpointKey: 'partner-api',
            providerKey: 'partner',
            tenantId: null,
            eventType: 'order.created',
            status: $status,
            idempotencyKey: 'idem-123',
            payloadJson: '{"order_id":42}',
            headersJson: null,
            signedHeadersJson: null,
            nextAttemptAt: new \DateTimeImmutable(),
            attemptCount: $attemptCount,
            maxAttempts: $maxAttempts,
        );
    }

    public function testMarkDeliveringUpdatesLeaseAndAttemptCount(): void
    {
        $delivery = $this->makeDelivery();
        $lease = new \DateTimeImmutable('+120 seconds');

        $delivery->markDelivering('worker-1', $lease);

        self::assertSame(OutboundStatus::Delivering, $delivery->getStatus());
        self::assertSame('worker-1', $delivery->getLeaseOwner());
        self::assertSame($lease, $delivery->getLeaseExpiresAt());
        self::assertSame(1, $delivery->getAttemptCount());
        self::assertNotNull($delivery->getLastAttemptAt());
    }

    public function testMarkDeliveredClearsLease(): void
    {
        $delivery = $this->makeDelivery(OutboundStatus::Delivering, attemptCount: 1);

        $delivery->markDelivered(200, '{"ok":true}', 'HTTP/1.1 200 OK');

        self::assertSame(OutboundStatus::Delivered, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());
        self::assertSame(200, $delivery->getLastResponseStatus());
        self::assertNull($delivery->getLeaseOwner());
        self::assertNull($delivery->getLeaseExpiresAt());
        self::assertNull($delivery->getLastError());
    }

    public function testMarkRetryScheduledSetsNextAttempt(): void
    {
        $delivery = $this->makeDelivery(OutboundStatus::Delivering, attemptCount: 1);
        $nextAt = new \DateTimeImmutable('+60 seconds');

        $delivery->markRetryScheduled($nextAt, 'Connection timeout');

        self::assertSame(OutboundStatus::RetryScheduled, $delivery->getStatus());
        self::assertSame($nextAt, $delivery->getNextAttemptAt());
        self::assertNull($delivery->getLeaseOwner());
        self::assertSame('Connection timeout', $delivery->getLastError());
    }

    public function testMarkFailedRecordsResponseDetails(): void
    {
        $delivery = $this->makeDelivery(OutboundStatus::Delivering, attemptCount: 5);

        $delivery->markFailed(500, 'Internal Server Error', 'Server error after 5 attempts');

        self::assertSame(OutboundStatus::Failed, $delivery->getStatus());
        self::assertSame(500, $delivery->getLastResponseStatus());
        self::assertSame('Internal Server Error', $delivery->getLastResponseBody());
        self::assertSame('Server error after 5 attempts', $delivery->getLastError());
        self::assertNull($delivery->getLeaseOwner());
    }

    public function testMarkCancelled(): void
    {
        $delivery = $this->makeDelivery(OutboundStatus::RetryScheduled, attemptCount: 2);

        $delivery->markCancelled();

        self::assertSame(OutboundStatus::Cancelled, $delivery->getStatus());
        self::assertNull($delivery->getLeaseOwner());
    }

    public function testResetToPendingClearsAttemptState(): void
    {
        $delivery = $this->makeDelivery(OutboundStatus::Failed, attemptCount: 5);

        $delivery->resetToPending();

        self::assertSame(OutboundStatus::Pending, $delivery->getStatus());
        self::assertSame(0, $delivery->getAttemptCount());
        self::assertNull($delivery->getLeaseOwner());
        self::assertNull($delivery->getLastError());
    }

    public function testHasAttemptsRemainingWhenUnderMax(): void
    {
        $delivery = $this->makeDelivery(attemptCount: 3, maxAttempts: 5);

        self::assertTrue($delivery->hasAttemptsRemaining());
    }

    public function testHasNoAttemptsRemainingWhenAtMax(): void
    {
        $delivery = $this->makeDelivery(attemptCount: 5, maxAttempts: 5);

        self::assertFalse($delivery->hasAttemptsRemaining());
    }

    public function testGettersReturnConstructorValues(): void
    {
        $delivery = $this->makeDelivery();

        self::assertSame('outbox-1', $delivery->getId());
        self::assertSame('partner-api', $delivery->getEndpointKey());
        self::assertSame('order.created', $delivery->getEventType());
        self::assertSame('idem-123', $delivery->getIdempotencyKey());
        self::assertSame('{"order_id":42}', $delivery->getPayloadJson());
    }
}
