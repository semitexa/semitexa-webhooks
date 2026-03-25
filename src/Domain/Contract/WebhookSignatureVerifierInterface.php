<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\VerificationResult;
use Semitexa\Webhooks\Domain\Model\WebhookVerificationInput;

interface WebhookSignatureVerifierInterface
{
    public function verify(WebhookVerificationInput $input): VerificationResult;
}
