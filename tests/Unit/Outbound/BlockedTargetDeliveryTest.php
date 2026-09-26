<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Webhooks\Application\Service\Outbound\BackoffCalculator;
use Semitexa\Webhooks\Application\Service\Outbound\CurlWebhookTransport;
use Semitexa\Webhooks\Application\Service\Outbound\OutboundTargetGuard;
use Semitexa\Webhooks\Application\Service\Outbound\OutboxClaimService;
use Semitexa\Webhooks\Application\Service\Outbound\WebhookDeliveryWorker;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookTransportInterface;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Enum\WebhookDirection;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\TransportResult;
use Semitexa\Webhooks\Domain\Model\WebhookEndpointDefinition;

final class BlockedTargetDeliveryTest extends TestCase
{
    #[Test]
    public function a_blocked_target_is_a_permanent_failure(): void
    {
        $result = $this->transport('http://127.0.0.1:8080/hook', allowPrivate: false)->send($this->delivery());

        self::assertFalse($result->success);
        self::assertNull($result->httpStatus);
        self::assertTrue($result->permanent, 'a policy refusal repeats on every attempt; retrying it only burns attempts');
    }

    #[Test]
    public function a_target_that_does_not_resolve_stays_retryable(): void
    {
        $transport = $this->transport('https://hooks.example/in', allowPrivate: false);
        (new ReflectionProperty(CurlWebhookTransport::class, 'targetGuard'))
            ->setValue($transport, new OutboundTargetGuard(static fn (): array => []));

        $result = $transport->send($this->delivery());

        self::assertFalse($result->success);
        self::assertFalse($result->permanent, 'a DNS miss may be transient');
    }

    #[Test]
    public function the_worker_fails_a_permanent_transport_failure_without_retrying(): void
    {
        $outbox = $this->createMock(OutboundDeliveryRepositoryInterface::class);
        $outbox->method('claimAndLease')->willReturn($this->delivery(attemptCount: 1));
        $outbox->expects(self::once())->method('markFailedIfOwned')->willReturn(true);
        $outbox->expects(self::never())->method('markRetryScheduledIfOwned');

        $outcome = $this->worker($outbox, TransportResult::failure(null, 'blocked', permanent: true))->processOne('w1');

        self::assertSame(OutboundStatus::Failed, $outcome->newStatus);
    }

    #[Test]
    public function the_worker_still_retries_a_transient_transport_failure(): void
    {
        $outbox = $this->createMock(OutboundDeliveryRepositoryInterface::class);
        $outbox->method('claimAndLease')->willReturn($this->delivery(attemptCount: 1));
        $outbox->expects(self::never())->method('markFailedIfOwned');
        $outbox->expects(self::once())->method('markRetryScheduledIfOwned')->willReturn(true);

        $this->worker($outbox, TransportResult::failure(null, 'cURL error (7)'))->processOne('w1');
    }

    #[Test]
    public function an_environment_proxy_is_not_used_for_a_guarded_delivery(): void
    {
        // The "proxy": a listener that never answers. A proxy resolves the
        // target host itself, so going through it would bypass the pinned,
        // guarded address.
        $proxy = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($proxy, $errstr);
        $proxyName = (string) stream_socket_get_name($proxy, false);

        // The target: a port nothing listens on, so a direct connect is refused at once.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $targetName = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $saved = [];
        foreach (['http_proxy' => "http://{$proxyName}", 'no_proxy' => '', 'NO_PROXY' => ''] as $var => $value) {
            $saved[$var] = getenv($var);
            putenv("{$var}={$value}");
        }

        try {
            $result = $this->transport("http://{$targetName}/hook", allowPrivate: true, timeout: 2)
                ->send($this->delivery());
        } finally {
            foreach ($saved as $var => $value) {
                putenv($value === false ? $var : "{$var}={$value}");
            }
        }

        $viaProxy = @stream_socket_accept($proxy, 0);
        fclose($proxy);

        self::assertFalse($result->success);
        self::assertFalse($viaProxy, 'the delivery connected to the environment proxy instead of the checked address');
    }

    private function transport(string $targetUrl, bool $allowPrivate, int $timeout = 5): CurlWebhookTransport
    {
        $endpoints = $this->createStub(WebhookEndpointDefinitionRepositoryInterface::class);
        $endpoints->method('findByEndpointKey')->willReturn(new WebhookEndpointDefinition(
            id: 'ep-1',
            endpointKey: 'orders',
            direction: WebhookDirection::Outbound,
            providerKey: 'test',
            enabled: true,
            tenantId: null,
            verificationMode: null,
            signingMode: null,
            secretRef: null,
            targetUrl: $targetUrl,
            timeoutSeconds: $timeout,
            maxAttempts: 5,
            initialBackoffSeconds: 30,
            maxBackoffSeconds: 3600,
            dedupeWindowSeconds: null,
            handlerClass: null,
            defaultHeaders: null,
            metadata: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        ));

        $config = new WebhookConfig();
        (new ReflectionProperty(WebhookConfig::class, 'allowPrivateTargets'))->setValue($config, $allowPrivate);

        $transport = new CurlWebhookTransport();
        (new ReflectionProperty(CurlWebhookTransport::class, 'endpointRepo'))->setValue($transport, $endpoints);
        (new ReflectionProperty(CurlWebhookTransport::class, 'config'))->setValue($transport, $config);

        return $transport;
    }

    private function worker(OutboundDeliveryRepositoryInterface $outbox, TransportResult $result): WebhookDeliveryWorker
    {
        $claim = new OutboxClaimService();
        (new ReflectionProperty(OutboxClaimService::class, 'outboxRepo'))->setValue($claim, $outbox);
        (new ReflectionProperty(OutboxClaimService::class, 'config'))->setValue($claim, new WebhookConfig());

        $transport = new class ($result) implements WebhookTransportInterface {
            public function __construct(private readonly TransportResult $result) {}

            public function send(OutboundDelivery $delivery): TransportResult
            {
                return $this->result;
            }
        };

        $worker = new WebhookDeliveryWorker();
        foreach ([
            'claimService' => $claim,
            'transport' => $transport,
            'outboxRepo' => $outbox,
            'attemptRepo' => $this->createStub(WebhookAttemptRepositoryInterface::class),
            'backoffCalculator' => new BackoffCalculator(),
        ] as $property => $value) {
            (new ReflectionProperty(WebhookDeliveryWorker::class, $property))->setValue($worker, $value);
        }

        return $worker;
    }

    private function delivery(int $attemptCount = 0): OutboundDelivery
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
            payloadJson: '{}',
            headersJson: null,
            signedHeadersJson: null,
            nextAttemptAt: new \DateTimeImmutable(),
            attemptCount: $attemptCount,
        );
    }
}
