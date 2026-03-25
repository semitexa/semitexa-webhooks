<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

final readonly class VerificationResult
{
    public function __construct(
        public bool $verified,
        public string $reason = '',
    ) {}

    public static function success(): self
    {
        return new self(true, 'Signature verified');
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason);
    }

    public static function notRequired(): self
    {
        return new self(true, 'Verification not required');
    }
}
