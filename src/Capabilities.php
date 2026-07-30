<?php

declare(strict_types=1);

namespace Semitexa\Webhooks;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'webhooks.inbound',
    summary: 'Verified, deduplicated receipt of webhooks from third parties.',
    useWhen: 'An external service posts events to you and replays or forged calls would be damaging.',
    avoidWhen: 'The caller is your own service inside the same trust boundary - an internal event is cheaper.',
    replaces: [
        'a signature check copy-pasted per provider into each endpoint',
        'a processed-ids table written by hand to survive redeliveries',
    ],
)]
#[Capability(
    id: 'webhooks.outbound',
    summary: 'Durable delivery of your events to subscriber endpoints, with retries and backoff.',
    useWhen: 'Someone else needs to hear about what happened here, and a dropped notification is a real loss.',
    avoidWhen: 'You control both ends - an in-process event listener is simpler and cannot fail in transit.',
    replaces: [
        'a fire-and-forget HTTP call from the handler that raised the event',
        'a retry loop and dead-letter table written per integration',
    ],
)]
final class Capabilities
{
}
