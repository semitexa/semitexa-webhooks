<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

final readonly class InboundWebhookEnvelope
{
    public function __construct(
        public string $endpointKey,
        public string $httpMethod,
        public string $requestUri,
        public array $headers,
        public string $rawBody,
        public ?string $contentType = null,
    ) {}
}
