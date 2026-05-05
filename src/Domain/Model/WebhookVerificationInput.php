<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

final readonly class WebhookVerificationInput
{
    public function __construct(
        public array $headers,
        public string $rawBody,
        public ?string $secretRef,
        public ?string $verificationMode,
        public ?string $tenantId = null,
    ) {}
}
