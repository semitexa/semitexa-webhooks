<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Webhooks\Application\Service\Outbound\CurlWebhookTransport;
use Semitexa\Webhooks\Application\Service\Outbound\OutboundRequestSigner;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

/**
 * Webhook delivery over the shared HTTP client (ep-outbound-http-client): the
 * guard's address is pinned, no redirect, no proxy, no retry of its own (the
 * outbox owns retries), and a header with a line break is refused for good
 * instead of writing a header of its own.
 */
final class WebhookTransportHttpClientTest extends TestCase
{
    #[Test]
    public function a_delivery_is_pinned_to_the_checked_address_and_sent_once(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(202, 'ok', ['X-Request-Id' => 'r1']));

        $result = $this->transport($http, ['X-Tenant' => 'acme'])->send($this->delivery('{"Trace":"t-1"}'));

        self::assertTrue($result->success);
        $http->assertSent(static fn (HttpRequest $r): bool => $r->method === 'POST'
            && $r->url === 'http://localhost:9/hook'
            && $r->body === '{"id":1}'
            && $r->header('Content-Type') === 'application/json'
            && $r->header('X-Tenant') === 'acme'
            && $r->header('Trace') === 't-1');
        $options = $http->recordedOptions()[0];
        self::assertSame(['localhost:9:127.0.0.1'], $options->pinnedResolve);
        self::assertTrue($options->noProxy);
        self::assertFalse($options->followRedirects);
        self::assertSame(1, $options->retry->maxAttempts, 'the outbox owns retries');
        self::assertTrue($options->truncateOversized, 'a large reply to a processed delivery is cut, not a redelivery');
        self::assertFalse($options->decodeContent);
        self::assertSame(5.0, $options->timeout);
    }

    #[Test]
    public function a_timeout_of_zero_falls_back_to_the_configured_default(): void
    {
        // 0 meant curl's "no timeout"; as a client timeout it was 1 ms.
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));

        $this->transport($http, null, 0)->send($this->delivery());

        self::assertSame((float) (new WebhookConfig())->getDefaultTimeoutSeconds(), $http->recordedOptions()[0]->timeout);
    }

    /** A send that outlived its lease was claimed and sent again by another worker meanwhile. */
    #[Test]
    public function a_long_endpoint_timeout_stays_within_half_the_lease(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));

        $this->transport($http, null, 300)->send($this->delivery());

        self::assertSame((float) intdiv((new WebhookConfig())->getDefaultLeaseSeconds(), 2), $http->recordedOptions()[0]->timeout);
    }

    /** Each retry is signed afresh: the outbox id is what a receiver recognises a second copy by. */
    #[Test]
    public function every_attempt_carries_the_outbox_id_as_its_event_id(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200));

        $this->transport($http)->send($this->delivery());

        self::assertSame('d-1', $http->recorded()[0]->header('X-Webhook-Event-Id'));
        self::assertSame('order.created', $http->recorded()[0]->header('X-Webhook-Event-Type'));
    }

    #[Test]
    public function a_header_with_a_line_break_is_a_permanent_failure_and_nothing_is_sent(): void
    {
        $http = new FakeHttpClient();

        $result = $this->transport($http)->send($this->delivery((string) json_encode(['X-A' => "v\r\nX-Injected: 1"])));

        self::assertFalse($result->success);
        self::assertTrue($result->permanent);
        $http->assertSentCount(0);
    }

    #[Test]
    public function a_delivery_whose_secret_is_missing_is_not_sent_unsigned(): void
    {
        $http = new FakeHttpClient();

        $result = $this->transport($http, secretRef: 'env:SEMITEXA_MISSING_SECRET_' . bin2hex(random_bytes(4)))->send($this->delivery());

        self::assertFalse($result->success);
        self::assertFalse($result->permanent, 'retried: a secret set before its attempts run out lets it through');
        $http->assertSentCount(0);
    }

    #[Test]
    public function an_endpoint_that_signs_without_a_secret_ref_is_a_permanent_failure(): void
    {
        $http = new FakeHttpClient();

        $result = $this->transport($http, signingMode: 'hmac-sha256')->send($this->delivery());

        self::assertFalse($result->success);
        self::assertTrue($result->permanent);
        $http->assertSentCount(0);
    }

    #[Test]
    public function a_signed_delivery_carries_a_timestamp_the_receiver_requires(): void
    {
        $var = 'SEMITEXA_TEST_SECRET_' . bin2hex(random_bytes(4));
        putenv($var . '=s3cret');
        try {
            $http = (new FakeHttpClient())->queue(HttpResponse::make(200));
            $this->transport($http, secretRef: 'env:' . $var)->send($this->delivery());
        } finally {
            putenv($var);
        }

        $http->assertSent(static fn (HttpRequest $r): bool => ctype_digit((string) $r->header('X-Webhook-Timestamp'))
            && str_starts_with((string) $r->header('X-Webhook-Signature'), 'sha256='));
        $this->addToAssertionCount(1);
    }

    /**
     * Disabling an endpoint stopped new deliveries only: the queued and
     * retrying ones kept going out. Now each try fails, retried and counted
     * as an attempt, so it is not permanent at once.
     */
    #[Test]
    public function a_disabled_endpoint_sends_nothing_and_its_delivery_is_retried(): void
    {
        $http = new FakeHttpClient();

        $result = $this->transport($http, enabled: false)->send($this->delivery());

        self::assertFalse($result->success);
        self::assertFalse($result->permanent, 'enabled again before its attempts run out, it goes out');
        self::assertSame([], $http->recorded());
    }

    /** A key deleted and made again by another tenant received the old tenant's payloads. */
    #[Test]
    public function an_endpoint_now_owned_by_another_tenant_never_receives_the_delivery(): void
    {
        $http = new FakeHttpClient();

        $result = $this->transport($http, tenantId: 'other')->send($this->delivery());

        self::assertTrue($result->permanent);
        self::assertSame([], $http->recorded());
    }

    /** @param array<string, string>|null $defaultHeaders */
    private function transport(FakeHttpClient $http, ?array $defaultHeaders = null, int $timeout = 5, ?string $secretRef = null, ?string $signingMode = null, bool $enabled = true, ?string $tenantId = null): CurlWebhookTransport
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findById')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-1',
            endpointKey: 'orders',
            direction: WebhookDirection::Outbound,
            providerKey: 'test',
            enabled: $enabled,
            tenantId: $tenantId,
            verificationMode: null,
            signingMode: $signingMode ?? ($secretRef === null ? null : 'hmac-sha256'),
            secretRef: $secretRef,
            targetUrl: 'http://localhost:9/hook',
            timeoutSeconds: $timeout,
            maxAttempts: 5,
            initialBackoffSeconds: 30,
            maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null,
            handlerClass: null,
            defaultHeaders: $defaultHeaders,
            metadata: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));
        $config = new WebhookConfig();
        (new ReflectionProperty(WebhookConfig::class, 'allowPrivateTargets'))->setValue($config, true);

        $transport = new CurlWebhookTransport();
        foreach (['endpointRepo' => $endpoints, 'config' => $config, 'http' => $http, 'signer' => new OutboundRequestSigner()] as $property => $value) {
            (new ReflectionProperty(CurlWebhookTransport::class, $property))->setValue($transport, $value);
        }

        return $transport;
    }

    private function delivery(?string $headersJson = null): OutboundDelivery
    {
        return new OutboundDelivery(
            id: 'd-1',
            endpointDefinitionId: 'ep-1',
            endpointKey: 'orders',
            providerKey: 'test',
            tenantId: null,
            eventType: 'order.created',
            status: OutboundStatus::Delivering,
            idempotencyKey: null,
            payloadJson: '{"id":1}',
            headersJson: $headersJson,
            signedHeadersJson: null,
            nextAttemptAt: new \DateTimeImmutable(),
            attemptCount: 0,
        );
    }
}
