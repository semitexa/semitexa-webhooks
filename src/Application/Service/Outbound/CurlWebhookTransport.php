<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpCallOptions;
use Semitexa\Core\Http\Client\HttpClientInterface;
use Semitexa\Core\Http\Client\HttpRequest;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\WebhookEndpointDefinitionRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookTransportInterface;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\TransportResult;

#[SatisfiesServiceContract(of: WebhookTransportInterface::class)]
final class CurlWebhookTransport implements WebhookTransportInterface
{
    #[InjectAsReadonly]
    protected WebhookEndpointDefinitionRepositoryInterface $endpointRepo;

    #[InjectAsReadonly]
    protected OutboundRequestSigner $signer;

    #[InjectAsReadonly]
    protected WebhookConfig $config;

    #[InjectAsReadonly]
    protected HttpClientInterface $http;

    private const MAX_LOGGED_RESPONSE_BYTES = 65_536;

    private ?OutboundTargetGuard $targetGuard = null;

    public function send(OutboundDelivery $delivery): TransportResult
    {
        // The endpoint the delivery was queued for, by id: found by key, a
        // key deleted and created again — by another tenant, say — received
        // the old one's payloads, signed with the new secret.
        $endpoint = $this->endpointRepo->findById($delivery->getEndpointDefinitionId());
        if ($endpoint === null) {
            return TransportResult::failure(null, "Endpoint not found: {$delivery->getEndpointKey()}", permanent: true);
        }
        if (($endpoint->getTenantId() ?? '') !== ($delivery->getTenantId() ?? '')) {
            return TransportResult::failure(null, "Endpoint {$delivery->getEndpointKey()} belongs to another tenant now.", permanent: true);
        }
        // Disabling an endpoint stopped new deliveries only: those queued and
        // retrying kept going out. Retried like a failed send, each try an
        // attempt: enabled again before they run out, it gets them; after
        // that they are failed, and webhook:replay:outbound sends them.
        if (!$endpoint->isEnabled()) {
            return TransportResult::failure(null, "Endpoint {$delivery->getEndpointKey()} is disabled.");
        }

        if ($endpoint->getTargetUrl() === null || $endpoint->getTargetUrl() === '') {
            return TransportResult::failure(null, "No target URL configured for endpoint: {$delivery->getEndpointKey()}");
        }

        try {
            $target = ($this->targetGuard ??= new OutboundTargetGuard())
                ->check($endpoint->getTargetUrl(), $this->config->allowsPrivateTargets());
        } catch (BlockedTargetException $e) {
            return TransportResult::failure(null, $e->getMessage(), permanent: !$e->retryable);
        }

        $body = $delivery->getPayloadJson();

        // Build headers: the endpoint's defaults, the delivery's own, then the
        // signature, each later one replacing an earlier of the same name.
        // The outbox id, the same on every retry: each retry is signed with a
        // new timestamp, so a receiver had nothing stable to recognise a
        // second copy by. The inbound side dedupes on it (bound to the body).
        $headers = [
            'Content-Type' => 'application/json',
            'X-Webhook-Event-Id' => $delivery->getId(),
            'X-Webhook-Event-Type' => $delivery->getEventType(),
        ];
        foreach ($endpoint->getDefaultHeaders() ?? [] as $key => $value) {
            $headers[(string) $key] = (string) $value;
        }
        if ($delivery->getHeadersJson() !== null) {
            $customHeaders = json_decode($delivery->getHeadersJson(), true);
            if (is_array($customHeaders)) {
                foreach ($customHeaders as $key => $value) {
                    $headers[(string) $key] = (string) $value;
                }
            }
        }
        if ($endpoint->getSigningMode() !== null && ($endpoint->getSecretRef() ?? '') === '') {
            // Signing asked for, nothing to sign with: never sent unsigned.
            // Permanent: only a change to the endpoint row can fix it.
            return TransportResult::failure(null, "Endpoint {$endpoint->getEndpointKey()} signs its webhooks but names no secret_ref.", permanent: true);
        }
        if ($endpoint->getSigningMode() !== null && $endpoint->getSecretRef() !== null) {
            try {
                $signature = $this->signer->sign($body, $endpoint->getSecretRef(), 'sha256', $endpoint->getTenantId());
            } catch (\RuntimeException $e) {
                // Not sent unsigned. Retried as usual, each try an attempt:
                // a secret set before they run out lets the delivery through;
                // after that it is failed, and webhook:replay:outbound sends it.
                return TransportResult::failure(null, $e->getMessage());
            }
            foreach ($signature as $key => $value) {
                $headers[(string) $key] = (string) $value;
            }
        }

        try {
            // A header holding a line break would write a header of its own:
            // HttpRequest refuses it, and resending will not change it.
            $request = HttpRequest::make('POST', $endpoint->getTargetUrl())->withHeaders($headers)->withBody($body);
        } catch (\InvalidArgumentException $e) {
            return TransportResult::failure(null, 'Invalid webhook header: ' . $e->getMessage(), permanent: true);
        }

        // Connect to the address the guard checked, not a fresh lookup (an IP
        // literal needs no pinning). No redirects: the next hop was never
        // checked. No proxy, not even one from http_proxy/https_proxy: a proxy
        // resolves the host itself, bypassing the pinned, guarded address. No
        // retries here: the outbox owns them (BackoffCalculator).
        $pinned = [];
        if (filter_var(trim($target['host'], '[]'), FILTER_VALIDATE_IP) === false) {
            $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            $pinned[] = sprintf('%s:%d:%s', $target['host'], $target['port'], $ip);
        }

        // 0 (nothing validates the column) used to mean curl's "no timeout";
        // as a client timeout it would be 1 ms. The configured default instead.
        $timeout = $endpoint->getTimeoutSeconds() > 0 ? $endpoint->getTimeoutSeconds() : $this->config->getDefaultTimeoutSeconds();
        // Within half the lease: a send that outlives the lease is claimed and
        // sent again by another worker while it is still running.
        $timeout = min($timeout, max(1, intdiv($this->config->getDefaultLeaseSeconds(), 2)));

        try {
            $response = $this->http()->send($request, new HttpCallOptions(
                timeout: (float) $timeout,
                connectTimeout: (float) min(10, $timeout),
                // The receiver answered: its body is kept for the attempt log,
                // cut at 64 KiB and as sent. A large or encoded reply to a
                // delivery it processed must not turn into a redelivery.
                maxResponseBytes: self::MAX_LOGGED_RESPONSE_BYTES,
                truncateOversized: true,
                decodeContent: false,
                followRedirects: false,
                noProxy: true,
                pinnedResolve: $pinned,
            ));
        } catch (HttpTransportException $e) {
            return TransportResult::failure(null, "Transport error ({$e->kind->value}): {$e->detail}");
        }

        $httpStatus = $response->status;
        $responseHeaders = $response->rawHeaders;
        $responseBody = $response->body;

        $success = $httpStatus >= 200 && $httpStatus < 300;

        if ($success) {
            return TransportResult::success($httpStatus, $responseBody, $responseHeaders);
        }

        return TransportResult::failure(
            $httpStatus,
            "HTTP {$httpStatus} response",
            $responseBody,
        );
    }

    private function http(): HttpClientInterface
    {
        // Set by the container; a transport built by hand (a test) gets one.
        return isset($this->http) ? $this->http : ($this->http = new CurlHttpClient());
    }
}
