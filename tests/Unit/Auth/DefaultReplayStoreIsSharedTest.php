<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ServiceContractRegistry;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\DatabaseWebhookReplayStore;

/**
 * The bound replay store was the in-memory one, which is per process. The
 * receiver asks only a store the workers share, so with the defaults it
 * checked no replay at all, and a captured signed request was processed again
 * under every new event id (tk-webhooks-replay-check-off-with-the-default-store).
 * The default is now the database store, which every worker shares.
 */
final class DefaultReplayStoreIsSharedTest extends TestCase
{
    #[Test]
    public function the_default_replay_store_is_one_every_worker_shares(): void
    {
        $bound = (new ServiceContractRegistry())->getContracts()[WebhookReplayStoreInterface::class] ?? null;

        self::assertSame(DatabaseWebhookReplayStore::class, $bound);
        self::assertTrue((new DatabaseWebhookReplayStore())->isShared());
    }
}
