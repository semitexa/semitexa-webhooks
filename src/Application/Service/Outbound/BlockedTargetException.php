<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

/**
 * A webhook target that must not be called: bad scheme, unresolvable, or non-public.
 *
 * A policy refusal repeats on every attempt, so it ends the delivery; only a
 * host that does not resolve (possibly a transient DNS failure) is retryable.
 */
final class BlockedTargetException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
