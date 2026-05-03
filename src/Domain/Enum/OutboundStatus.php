<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Enum;

enum OutboundStatus: string
{
    case Pending = 'pending';
    case Delivering = 'delivering';
    case RetryScheduled = 'retry_scheduled';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
