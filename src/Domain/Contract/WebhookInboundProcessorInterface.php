<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\InboundWebhookEnvelope;

interface WebhookInboundProcessorInterface
{
    public function process(InboundWebhookEnvelope $envelope): void;
}
