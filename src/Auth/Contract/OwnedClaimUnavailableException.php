<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth\Contract;

/**
 * A store that implements {@see OwnedClaimStoreInterface} cannot record an
 * owner right now, and wrote nothing: its table has no `owner` column until
 * orm:sync adds it. Not a failure of the store, so a caller falls back rather
 * than fail every request between a deploy and its schema sync.
 */
final class OwnedClaimUnavailableException extends \RuntimeException
{
}
