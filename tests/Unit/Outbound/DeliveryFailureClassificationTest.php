<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Outbound\WebhookDeliveryWorker;

final class DeliveryFailureClassificationTest extends TestCase
{
    /** @return iterable<string, array{?int, bool}> */
    public static function statuses(): iterable
    {
        yield 'bad request' => [400, true];
        yield 'gone' => [410, true];
        yield 'unprocessable' => [422, true];
        // "Not now", not "never": a rate limit must not end the delivery on attempt one.
        yield 'too many requests' => [429, false];
        yield 'request timeout' => [408, false];
        yield 'server error' => [503, false];
        yield 'transport failure' => [null, false];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function only_a_rejection_that_will_repeat_is_permanent(?int $status, bool $permanent): void
    {
        self::assertSame($permanent, WebhookDeliveryWorker::isPermanentFailure($status));
    }
}
