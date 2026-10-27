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
        $delivery = $this->delivery(OutboundStatus::Delivering, new \DateTimeImmutable('+2 minutes'));
        [$tester, $saved] = $this->replay($delivery);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('being sent', $tester->getDisplay());
        self::assertSame(0, $saved->count);
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
     * @param array<string, mixed> $options
     * @return array{CommandTester, object{count: int, allowDelivered: ?bool}}
     */
    private function replay(OutboundDelivery $delivery, array $options = []): array
    {
        $saved = new class { public int $count = 0; public ?bool $allowDelivered = null; };
        $outbox = $this->createStub(OutboundDeliveryRepositoryInterface::class);
        $outbox->method('findById')->willReturn($delivery);
        $outbox->method('resetToPendingUnlessLeased')->willReturnCallback(static function (string $id, bool $allowDelivered) use ($saved): bool {
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
