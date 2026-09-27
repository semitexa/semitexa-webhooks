<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Outbound\OutboundTargetGuard;

/**
 * The guard as an HTTP worker runs it: inside a coroutine, under
 * SWOOLE_HOOK_ALL. There the hooked DNS functions go through
 * Swoole\RemoteObject\Client, which on 6.2.x leaves one client behind per
 * call — and answered nothing, so every host looked unresolvable.
 *
 * A separate process: hook flags are process-wide.
 */
final class OutboundTargetGuardCoroutineResolveTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function a_coroutine_resolves_without_the_remote_object_client(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole extension is required.');
        }

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $clients = new \ReflectionProperty(\Swoole\RemoteObject\Client::class, 'clients');
        $resolve = new \ReflectionMethod(OutboundTargetGuard::class, 'resolveHost');

        $before = count((array) $clients->getValue());
        $ips = [];
        \Swoole\Coroutine\run(static function () use ($resolve, &$ips): void {
            for ($i = 0; $i < 3; $i++) {
                $ips = $resolve->invoke(null, 'localhost');
            }
        });

        self::assertContains('127.0.0.1', $ips);
        self::assertSame($before, count((array) $clients->getValue()), 'each lookup left a RemoteObject client behind');
    }
}
