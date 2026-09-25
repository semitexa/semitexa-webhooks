<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Fixtures;

use Semitexa\Webhooks\Application\Service\Outbound\OutboundRequestSigner;
use Semitexa\Webhooks\Domain\Contract\WebhookTransportInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\TransportResult;

/**
 * In-memory {@see WebhookTransportInterface} for tests.
 *
 * Mirrors {@see \Semitexa\Webhooks\Application\Service\Outbound\CurlWebhookTransport}'s
 * behaviour minus the actual network call: it resolves the endpoint,
 * signs the request body when the endpoint has signing configured (using
 * the real {@see OutboundRequestSigner}, exactly as the worker's transport
 * would), and always reports success. Every send is recorded in {@see $sent}
 * so tests can assert "sent exactly once" and inspect the signed headers.
 *
 * `send()` never yields mid-update — appending to `$sent` is a single
 * PHP statement, so it is safe to call from multiple coroutines racing
 * for the same delivery's transport send under Swoole's cooperative
 * scheduler.
 */
final class InMemoryWebhookTransport implements WebhookTransportInterface
{
    /** @var list<array{deliveryId: string, endpointKey: string, body: string, headers: array<string, string>}> */
    public array $sent = [];

    public function __construct(
        private readonly InMemoryWebhookEndpointDefinitionRepository $endpointRepo,
        private readonly OutboundRequestSigner $signer,
    ) {}

    public function send(OutboundDelivery $delivery): TransportResult
    {
        $endpoint = $this->endpointRepo->findByEndpointKey($delivery->getEndpointKey());
        if ($endpoint === null) {
            return TransportResult::failure(null, "Endpoint not found: {$delivery->getEndpointKey()}");
        }

        $body = $delivery->getPayloadJson();
        $headers = [];

        if ($endpoint->getSigningMode() !== null && $endpoint->getSecretRef() !== null) {
            $headers = $this->signer->sign($body, $endpoint->getSecretRef(), 'sha256');
        }

        $this->sent[] = [
            'deliveryId' => $delivery->getId(),
            'endpointKey' => $delivery->getEndpointKey(),
            'body' => $body,
            'headers' => $headers,
        ];

        return TransportResult::success(200, 'ok');
    }
}
