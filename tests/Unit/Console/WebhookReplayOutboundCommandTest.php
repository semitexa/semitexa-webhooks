<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Webhooks\Application\Console\Command\WebhookReplayOutboundCommand;
use Semitexa\Webhooks\Domain\Contract\OutboundDeliveryRepositoryInterface;
use Semitexa\Webhooks\Domain\Contract\WebhookAttemptRepositoryInterface;
use Semitexa\Webhooks\Domain\Enum\OutboundStatus;
use Semitexa\Webhooks\Domain\Model\OutboundDelivery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Replay reset anything to pending: a delivery being sent right now lost its
 * lease and went out twice, and a delivered one was sent again unasked.
 */
final class WebhookReplayOutboundCommandTest extends TestCase
{
    #[Test]
    public function a_delivery_a_worker_is_sending_is_left_alone(): void
    {
        $lease = new \DateTimeImmutable('+2 minutes');
        [$tester, $saved] = $this->replay($this->delivery(OutboundStatus::Delivering, $lease));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(1, $saved->calls, 'the reset decides');
        self::assertSame(0, $saved->count);
        self::assertStringContainsString(
            "Outbound delivery d-1 is being sent by worker-7 until {$lease->format('H:i:s')} UTC; try again after that.",
            self::oneLine($tester),
        );
    }

    /**
     * The command compared the lease with PHP's clock before the reset, which
     * compares it with the database's: a lease already over by the database
     * clock, but not yet by a host clock behind it, was refused for nothing.
     */
    #[Test]
    public function a_lease_over_by_the_database_clock_is_reset_whatever_the_host_clock_says(): void
    {
        $delivery = $this->delivery(OutboundStatus::Delivering, new \DateTimeImmutable('+30 seconds'));
        [$tester, $saved] = $this->replay($delivery, databaseNow: new \DateTimeImmutable('+1 minute'));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), self::oneLine($tester));
        self::assertSame(1, $saved->count);
        self::assertSame(OutboundStatus::Pending, $delivery->getStatus());
    }

    #[Test]
    public function a_delivered_one_is_sent_again_only_with_force(): void
    {
        [$tester, $saved] = $this->replay($this->delivery(OutboundStatus::Delivered));
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $saved->count);

        [$tester, $saved] = $this->replay($this->delivery(OutboundStatus::Delivered), ['--force' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, $saved->count);
        self::assertTrue($saved->allowDelivered, '--force reaches the reset, or it refuses a delivered row');
    }

    #[Test]
    public function a_failed_one_and_a_stale_lease_are_reset(): void
    {
        [$tester, $saved] = $this->replay($this->delivery(OutboundStatus::Failed));
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFalse($saved->allowDelivered, 'without --force the reset may not touch a delivered row');

        [$tester, $saved] = $this->replay($this->delivery(OutboundStatus::Delivering, new \DateTimeImmutable('-1 minute')));
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'a lease that ran out belongs to a worker that died');
        self::assertFalse($saved->allowDelivered);
    }

    /**
     * The stubbed reset refuses what the SQL one refuses, judged by
     * $databaseNow: a live lease, and a delivered row without --force.
     *
     * @param array<string, mixed> $options
     * @return array{CommandTester, object{calls: int, count: int, allowDelivered: ?bool}}
     */
    private function replay(OutboundDelivery $delivery, array $options = [], ?\DateTimeImmutable $databaseNow = null): array
    {
        $databaseNow ??= new \DateTimeImmutable();
        $saved = new class { public int $calls = 0; public int $count = 0; public ?bool $allowDelivered = null; };
        $outbox = $this->createStub(OutboundDeliveryRepositoryInterface::class);
        $outbox->method('findById')->willReturn($delivery);
        $outbox->method('resetToPendingUnlessLeased')->willReturnCallback(static function (string $id, bool $allowDelivered) use ($saved, $delivery, $databaseNow): bool {
            $saved->calls++;
            $lease = $delivery->getLeaseExpiresAt();
            if (($delivery->getStatus() === OutboundStatus::Delivering && $lease !== null && $lease > $databaseNow)
                || ($delivery->getStatus() === OutboundStatus::Delivered && !$allowDelivered)) {
                return false;
            }
            $saved->count++;
            $saved->allowDelivered = $allowDelivered;

            return true;
        });
        $attempts = $this->createStub(WebhookAttemptRepositoryInterface::class);

        $container = new class ($outbox, $attempts) implements ContainerInterface {
            public function __construct(private object $outbox, private object $attempts) {}
            public function get(string $id): mixed
            {
                return $id === OutboundDeliveryRepositoryInterface::class ? $this->outbox : $this->attempts;
            }
            public function has(string $id): bool { return true; }
        };

        $command = new WebhookReplayOutboundCommand();
        (new \ReflectionProperty(WebhookReplayOutboundCommand::class, 'container'))->setValue($command, $container);
        $tester = new CommandTester($command);
        $tester->execute(['id' => 'd-1'] + $options);

        return [$tester, $saved];
    }

    /** The display with SymfonyStyle's line wrapping undone. */
    private static function oneLine(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function delivery(OutboundStatus $status, ?\DateTimeImmutable $leaseExpiresAt = null): OutboundDelivery
    {
        return new OutboundDelivery(
            id: 'd-1',
            endpointDefinitionId: 'ep-1',
            endpointKey: 'orders',
            providerKey: 'test',
            tenantId: null,
            eventType: 'order.created',
            status: $status,
            idempotencyKey: null,
            payloadJson: '{}',
            headersJson: null,
            signedHeadersJson: null,
            nextAttemptAt: new \DateTimeImmutable(),
            attemptCount: 1,
            leaseOwner: $leaseExpiresAt === null ? null : 'worker-7',
            leaseExpiresAt: $leaseExpiresAt,
        );
    }
}
