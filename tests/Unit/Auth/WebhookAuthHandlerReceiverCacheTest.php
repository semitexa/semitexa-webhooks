<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Auth\WebhookAuthHandler;

/**
 * WebhookAuthHandler runs early in the auth chain (priority 5) on EVERY request,
 * not just webhook routes. It used to reflect the payload class on each request
 * only to find it isn't a webhook and pass through. The receiver lookup is now
 * memoized per payload class — the pass-through behaviour must be unchanged and
 * the answer cached so reflection runs once per class.
 */
final class WebhookAuthHandlerReceiverCacheTest extends TestCase
{
    #[Test]
    public function a_non_webhook_payload_passes_through_to_the_next_handler(): void
    {
        $handler = new WebhookAuthHandler();

        self::assertNull($handler->handle(new NonWebhookPayload()));
        self::assertNull($handler->handle(new NonWebhookPayload()), 'the cached path still passes through');
    }

    #[Test]
    public function the_receiver_lookup_is_memoized_per_payload_class(): void
    {
        $handler = new WebhookAuthHandler();
        $handler->handle(new NonWebhookPayload());

        /** @var array<class-string, mixed> $cache */
        $cache = (new \ReflectionProperty(WebhookAuthHandler::class, 'receiverAttrByClass'))->getValue();

        self::assertArrayHasKey(NonWebhookPayload::class, $cache, 'the per-class answer must be cached');
        self::assertNull($cache[NonWebhookPayload::class], 'a class with no #[AsWebhookReceiver] caches null');
    }
}

/** A payload with no #[AsWebhookReceiver] — the common non-webhook request. */
final class NonWebhookPayload
{
}
