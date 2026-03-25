<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

final readonly class TransportResult
{
    public function __construct(
        public bool $success,
        public ?int $httpStatus = null,
        public ?string $responseBody = null,
        public ?string $errorMessage = null,
        public ?string $responseHeaders = null,
    ) {}

    public static function success(int $httpStatus, ?string $responseBody = null, ?string $responseHeaders = null): self
    {
        return new self(true, $httpStatus, $responseBody, responseHeaders: $responseHeaders);
    }

    public static function failure(?int $httpStatus, string $errorMessage, ?string $responseBody = null): self
    {
        return new self(false, $httpStatus, $responseBody, $errorMessage);
    }
}
