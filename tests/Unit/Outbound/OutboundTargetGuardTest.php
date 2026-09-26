<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Outbound\BlockedTargetException;
use Semitexa\Webhooks\Application\Service\Outbound\OutboundTargetGuard;

final class OutboundTargetGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forbiddenTargets(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:8080/hook'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'private network' => ['https://10.0.0.5/hook'];
        yield 'ipv6 loopback' => ['http://[::1]/hook'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/hook'];
        yield 'name resolving inside' => ['https://internal.example/hook'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://example.com/'];
    }

    #[Test]
    #[DataProvider('forbiddenTargets')]
    public function a_non_public_or_non_http_target_is_blocked(string $url): void
    {
        $this->expectException(BlockedTargetException::class);

        $this->guard()->check($url, allowPrivate: false);
    }

    #[Test]
    public function one_private_address_among_several_blocks_the_host(): void
    {
        // A rebinding-style answer: the check must not pass on the public one.
        $guard = new OutboundTargetGuard(static fn (): array => ['93.184.216.34', '10.0.0.5']);

        $this->expectException(BlockedTargetException::class);
        $guard->check('https://mixed.example/hook', allowPrivate: false);
    }

    #[Test]
    public function a_public_target_is_pinned_to_the_address_that_was_checked(): void
    {
        self::assertSame(
            ['host' => 'hooks.example', 'port' => 443, 'ip' => '93.184.216.34'],
            $this->guard()->check('https://hooks.example/in', allowPrivate: false),
        );
    }

    #[Test]
    public function private_targets_are_allowed_when_configured(): void
    {
        self::assertSame('127.0.0.1', $this->guard()->check('http://127.0.0.1:8080/hook', allowPrivate: true)['ip']);
    }

    #[Test]
    public function an_unresolvable_host_is_blocked(): void
    {
        $this->expectException(BlockedTargetException::class);

        (new OutboundTargetGuard(static fn (): array => []))->check('https://nowhere.example/', allowPrivate: true);
    }

    private function guard(): OutboundTargetGuard
    {
        return new OutboundTargetGuard(static fn (string $host): array => match ($host) {
            'hooks.example' => ['93.184.216.34'],
            'internal.example' => ['192.168.1.20'],
            default => [],
        });
    }
}
