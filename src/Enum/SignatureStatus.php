<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Enum;

enum SignatureStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case NotRequired = 'not_required';
}
