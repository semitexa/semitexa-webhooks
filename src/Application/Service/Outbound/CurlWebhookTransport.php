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
        $endpoint = $this->endpointRepo->findByEndpointKey($delivery->getEndpointKey());
        if ($endpoint === null) {
            return TransportResult::failure(null, "Endpoint not found: {$delivery->getEndpointKey()}");
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
        $headers = ['Content-Type' => 'application/json'];
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
        if ($endpoint->getSigningMode() !== null && $endpoint->getSecretRef() !== null) {
            foreach ($this->signer->sign($body, $endpoint->getSecretRef(), 'sha256') as $key => $value) {
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
