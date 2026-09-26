<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

/** A webhook target that must not be called: bad scheme, unresolvable, or non-public. */
final class BlockedTargetException extends \RuntimeException
{
}
