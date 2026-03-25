<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Enum;

enum WebhookDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
