<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Domain\Enum\InboundStatus;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Enum\SignatureStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;

final class EnumTest extends TestCase
{
    public function testWebhookDirectionValues(): void
    {
        self::assertSame('inbound', WebhookDirection::Inbound->value);
        self::assertSame('outbound', WebhookDirection::Outbound->value);
        self::assertCount(2, WebhookDirection::cases());
    }

    public function testInboundStatusValues(): void
    {
        self::assertSame('received', InboundStatus::Received->value);
        self::assertSame('verified', InboundStatus::Verified->value);
        self::assertSame('rejected_signature', InboundStatus::RejectedSignature->value);
        self::assertSame('duplicate_ignored', InboundStatus::DuplicateIgnored->value);
        self::assertSame('processing', InboundStatus::Processing->value);
        self::assertSame('processed', InboundStatus::Processed->value);
        self::assertSame('failed', InboundStatus::Failed->value);
        self::assertCount(7, InboundStatus::cases());
    }

    public function testOutboundStatusValues(): void
    {
        self::assertSame('pending', OutboundStatus::Pending->value);
        self::assertSame('delivering', OutboundStatus::Delivering->value);
        self::assertSame('retry_scheduled', OutboundStatus::RetryScheduled->value);
        self::assertSame('delivered', OutboundStatus::Delivered->value);
        self::assertSame('failed', OutboundStatus::Failed->value);
        self::assertSame('cancelled', OutboundStatus::Cancelled->value);
        self::assertCount(6, OutboundStatus::cases());
    }

    public function testSignatureStatusValues(): void
    {
        self::assertSame('pending', SignatureStatus::Pending->value);
        self::assertSame('verified', SignatureStatus::Verified->value);
        self::assertSame('rejected', SignatureStatus::Rejected->value);
        self::assertSame('not_required', SignatureStatus::NotRequired->value);
        self::assertCount(4, SignatureStatus::cases());
    }

    public function testEnumsAreConstructibleFromStrings(): void
    {
        self::assertSame(InboundStatus::Verified, InboundStatus::from('verified'));
        self::assertSame(OutboundStatus::Pending, OutboundStatus::from('pending'));
        self::assertSame(SignatureStatus::Rejected, SignatureStatus::from('rejected'));
        self::assertSame(WebhookDirection::Inbound, WebhookDirection::from('inbound'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(InboundStatus::tryFrom('nonexistent'));
        self::assertNull(OutboundStatus::tryFrom('nonexistent'));
        self::assertNull(SignatureStatus::tryFrom('nonexistent'));
        self::assertNull(WebhookDirection::tryFrom('nonexistent'));
    }
}
