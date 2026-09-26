<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
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

        // Build headers
        $headers = ['Content-Type: application/json'];

        // Default headers from endpoint definition
        if ($endpoint->getDefaultHeaders() !== null) {
            foreach ($endpoint->getDefaultHeaders() as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
        }

        // Custom headers from delivery
        if ($delivery->getHeadersJson() !== null) {
            $customHeaders = json_decode($delivery->getHeadersJson(), true);
            if (is_array($customHeaders)) {
                foreach ($customHeaders as $key => $value) {
                    $headers[] = "{$key}: {$value}";
                }
            }
        }

        // Sign request if configured
        if ($endpoint->getSigningMode() !== null && $endpoint->getSecretRef() !== null) {
            $signedHeaders = $this->signer->sign($body, $endpoint->getSecretRef(), 'sha256');
            foreach ($signedHeaders as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint->getTargetUrl(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $endpoint->getTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => min(10, $endpoint->getTimeoutSeconds()),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // No proxy, not even one from http_proxy/https_proxy: a proxy
            // resolves the host itself, bypassing the pinned, guarded address.
            CURLOPT_PROXY => '',
        ]);
        // Connect to the address the guard checked, not a fresh lookup (an IP
        // literal needs no pinning).
        if (filter_var(trim($target['host'], '[]'), FILTER_VALIDATE_IP) === false) {
            $pinned = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            curl_setopt($ch, CURLOPT_RESOLVE, [sprintf('%s:%d:%s', $target['host'], $target['port'], $pinned)]);
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);

        if ($errno !== 0) {
            $error = curl_error($ch);
            curl_close($ch);
            return TransportResult::failure(null, "cURL error ({$errno}): {$error}");
        }

        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr((string) $response, 0, $headerSize);
        $responseBody = substr((string) $response, $headerSize);

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
}
