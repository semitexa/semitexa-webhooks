<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Contract;

use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Semitexa\Webhooks\Domain\Model\TransportResult;

interface WebhookTransportInterface
{
    public function send(OutboundDelivery $delivery): TransportResult;
}
