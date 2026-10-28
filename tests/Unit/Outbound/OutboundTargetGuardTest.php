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
        yield 'ipv4-compatible loopback' => ['http://[::7f00:1]/hook'];
        yield 'ipv4-compatible private' => ['http://[::a00:5]/hook'];
        yield 'name resolving inside' => ['https://internal.example/hook'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://example.com/'];
        yield 'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/'];
        yield 'local nat64' => ['http://[64:ff9b:1::808:808]/'];
        yield '6to4 metadata' => ['http://[2002:a9fe:a9fe::1]/'];
        yield 'site-local' => ['http://[fec0::1]/'];
        yield '6to4 relay' => ['http://192.88.99.1/'];
    }

    /**
     * cURL spells these differently than they were checked (punycode,
     * %-decoded) and misses the pinned entry, or reads them as an address
     * itself: resolved afresh, unchecked. A resolver that answers a public
     * address for every name leaves only the spelling checks to refuse them.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function respelledTargets(): iterable
    {
        $ascii = 'Webhook target host must be a plain ASCII name (write an international name in its xn-- form).';
        $numeric = 'Webhook target host looks like an address but is not one written in full.';
        yield 'international name' => ['http://ä.hooks.example/hook', $ascii];
        yield 'percent-escaped name' => ['http://hooks%2eexample/hook', $ascii];
        yield 'short loopback' => ['http://127.1/', $numeric];
        yield 'hex loopback' => ['http://0x7f.1/', $numeric];
        yield 'decimal loopback' => ['http://2130706433/', $numeric];
    }

    #[Test]
    #[DataProvider('respelledTargets')]
    public function a_host_curl_would_spell_another_way_is_refused_before_it_is_resolved(string $url, string $message): void
    {
        $this->expectException(BlockedTargetException::class);
        $this->expectExceptionMessage($message);

        (new OutboundTargetGuard(static fn (): array => ['93.184.216.34']))->check($url, allowPrivate: false);
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
    public function an_ipv4_compatible_literal_is_judged_by_its_embedded_address(): void
    {
        self::assertFalse(OutboundTargetGuard::isPublic('::7f00:1'), 'embeds 127.0.0.1');
        self::assertTrue(OutboundTargetGuard::isPublic('::5db8:d822'), 'embeds public 93.184.216.34');
    }

    #[Test]
    public function an_international_name_in_its_xn_form_is_pinned_as_written(): void
    {
        self::assertSame('xn--4ca.hooks.example', $this->guard()->check('https://xn--4ca.hooks.example/in', allowPrivate: false)['host']);
    }

    /** The message lands in last_error: a tenant reading it could map internal names to addresses. */
    #[Test]
    public function a_blocked_target_does_not_name_the_internal_address(): void
    {
        try {
            (new OutboundTargetGuard(static fn (): array => ['10.1.2.3']))->check('https://billing.internal/hook', allowPrivate: false);
            self::fail('blocked');
        } catch (BlockedTargetException $e) {
            self::assertSame('Webhook target host "billing.internal" resolves to a non-public address; set WEBHOOK_ALLOW_PRIVATE_TARGETS=true to allow it.', $e->getMessage());
        }
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
            'hooks.example', 'xn--4ca.hooks.example' => ['93.184.216.34'],
            'internal.example' => ['192.168.1.20'],
            default => [],
        });
    }
}
