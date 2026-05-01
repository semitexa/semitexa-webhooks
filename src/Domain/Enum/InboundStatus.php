<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Enum;

enum InboundStatus: string
{
    case Received = 'received';
    case Verified = 'verified';
    case RejectedSignature = 'rejected_signature';
    case DuplicateIgnored = 'duplicate_ignored';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
