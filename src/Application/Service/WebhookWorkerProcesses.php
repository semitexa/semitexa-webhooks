<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\AsWorkerProcessSource;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Queue\WorkerProcess;

/**
 * webhook:work, the loop that delivers the outbox. Without it a webhook is
 * recorded and never sent.
 */
#[AsService]
#[AsWorkerProcessSource]
final class WebhookWorkerProcesses implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['webhook:work'])];
    }
}
