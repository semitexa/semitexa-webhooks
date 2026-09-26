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
 * so tests can assert "sent exactly once" and inspect the ordered
 * "Name: value" header lines that would have gone on the wire.
 *
 * `send()` never yields mid-update — appending to `$sent` is a single
 * PHP statement, so it is safe to call from multiple coroutines racing
 * for the same delivery's transport send under Swoole's cooperative
 * scheduler.
 */
final class InMemoryWebhookTransport implements WebhookTransportInterface
{
    /** @var list<array{deliveryId: string, endpointKey: string, body: string, headers: list<string>}> */
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

        // Same sources, same order, same wire format as CurlWebhookTransport:
        // endpoint defaults, then the delivery's own headers, then the
        // signature, each appended as a "Name: value" line. Nothing is merged
        // by name, so a header set both as an endpoint default and on the
        // delivery is recorded twice, exactly as cURL would send it.
        $headers = ['Content-Type: application/json'];

        foreach ($endpoint->getDefaultHeaders() ?? [] as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        $customHeaders = $delivery->getHeadersJson() !== null
            ? json_decode($delivery->getHeadersJson(), true)
            : null;
        if (is_array($customHeaders)) {
            foreach ($customHeaders as $key => $value) {
                $headers[] = $key . ': ' . (is_scalar($value) ? (string) $value : '');
            }
        }

        if ($endpoint->getSigningMode() !== null && $endpoint->getSecretRef() !== null) {
            foreach ($this->signer->sign($body, $endpoint->getSecretRef(), 'sha256') as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
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
