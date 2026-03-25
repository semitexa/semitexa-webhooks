<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\OutboundWebhookMessage;

interface WebhookPublisherInterface
{
    public function publish(OutboundWebhookMessage $message): void;
}
