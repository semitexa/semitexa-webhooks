<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Webhooks\Application\Console\Command\WebhookCleanupCommand;
use Semitexa\Webhooks\Application\Service\WebhookRetentionService;
use Semitexa\Webhooks\Auth\Contract\ExpiringWebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;
use Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore;
use Semitexa\Webhooks\Configuration\WebhookConfig;
use Semitexa\Webhooks\Domain\Contract\InboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Cleanup asked the container for the MySQL store's class, which the
 * container cannot build, so the keys of a bound store were never deleted.
 */
final class WebhookCleanupReplayStoreTest extends TestCase
{
    #[Test]
    public function the_bound_store_is_cleaned_when_its_keys_need_deleting(): void
    {
        $store = new class implements ExpiringWebhookReplayStoreInterface {
            public int $cleaned = 0;
            public function seen(string $key): bool { return false; }
            public function markSeen(string $key): void {}
            public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool { return true; }
            public function clear(): void {}
            public function isShared(): bool { return true; }
            public function diagnosticName(): string { return 'test'; }
            public function cleanupExpired(?\DateTimeImmutable $now = null, ?int $limit = null, ?string $tenantId = null): int { $this->cleaned++; return 3; }
            public function countExpired(?\DateTimeImmutable $now = null, ?string $tenantId = null): int { return 3; }
        };

        self::assertSame('3', $this->cleanup($store));
        self::assertSame(1, $store->cleaned);
    }

    #[Test]
    public function a_store_that_expires_its_own_keys_is_skipped(): void
    {
        self::assertStringContainsString('no MySQL backing', $this->cleanup(new InMemoryWebhookReplayStore()));
    }

    /** The rows were purged, the replay keys were not: a scheduler read success and nobody looked. */
    #[Test]
    public function a_replay_store_that_cannot_be_built_fails_the_run(): void
    {
        $command = new WebhookCleanupCommand();
        (new \ReflectionProperty(WebhookCleanupCommand::class, 'container'))->setValue($command, new class ($this->retention()) implements ContainerInterface {
            public function __construct(private object $retention) {}
            public function get(string $id): mixed
            {
                return $id === WebhookRetentionService::class ? $this->retention : throw new \RuntimeException("cannot build {$id}");
            }
            public function has(string $id): bool { return true; }
        });
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Replay store could not be built: cannot build ' . WebhookReplayStoreInterface::class, $tester->getDisplay());
    }

    private function retention(): WebhookRetentionService
    {
        $service = (new \ReflectionClass(WebhookRetentionService::class))->newInstanceWithoutConstructor();
        $set = static fn (string $name, object $value) => (new \ReflectionProperty(WebhookRetentionService::class, $name))->setValue($service, $value);
        $set('config', WebhookConfig::withOverrides(retentionDays: 30));
        $set('inboxRepo', $this->createStub(InboundDeliveryRepositoryInterface::class));
        $set('outboxRepo', $this->createStub(OutboundDeliveryRepositoryInterface::class));
        $set('attemptRepo', $this->createStub(WebhookAttemptRepositoryInterface::class));

        return $service;
    }

    private function cleanup(object $store): string
    {
        $command = new WebhookCleanupCommand();
        // Only the bound interface answers: asking for anything else (the
        // MySQL store's class, as cleanup once did) fails as it would.
        (new \ReflectionProperty(WebhookCleanupCommand::class, 'container'))->setValue($command, new class ($store) implements ContainerInterface {
            public function __construct(private object $store) {}
            public function get(string $id): mixed
            {
                return $id === WebhookReplayStoreInterface::class ? $this->store : throw new \RuntimeException("not bound: {$id}");
            }
            public function has(string $id): bool { return $id === WebhookReplayStoreInterface::class; }
        });

        return (new \ReflectionMethod(WebhookCleanupCommand::class, 'cleanupReplayKeys'))
            ->invoke($command, new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), false, 100, null);
    }
}
