<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Enum;

enum WebhookDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
