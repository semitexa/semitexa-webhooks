<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Webhooks\Application\Service\Outbound\WebhookDeliveryWorker;

/**
 * REGRESSION (tk-worker-runs-mail-work). webhook:work builds its delivery
 * worker through the container, and the worker was not a service: the command
 * failed with "not found" on every start. Nothing ran webhook:work before the
 * scaffold's worker container did, so no webhook was ever delivered.
 */
final class WebhookWorkResolvesItsWorkerTest extends TestCase
{
    #[Test]
    public function the_container_builds_the_delivery_worker_webhook_work_runs(): void
    {
        self::assertInstanceOf(WebhookDeliveryWorker::class, ContainerFactory::get()->get(WebhookDeliveryWorker::class));
    }
}
