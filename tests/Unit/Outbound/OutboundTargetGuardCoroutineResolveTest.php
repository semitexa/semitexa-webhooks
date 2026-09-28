<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
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
    #[RequiresPhpExtension('swoole')]
    public function a_coroutine_resolves_without_the_remote_object_client(): void
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $clients = new \ReflectionProperty(\Swoole\RemoteObject\Client::class, 'clients');
        $resolve = new \ReflectionMethod(OutboundTargetGuard::class, 'resolveHost');

        $before = count((array) $clients->getValue());
        $runs = [];
        \Swoole\Coroutine\run(static function () use ($resolve, &$runs): void {
            for ($i = 0; $i < 3; $i++) {
                $runs[] = $resolve->invoke(null, 'localhost');
            }
        });

        self::assertCount(3, $runs);
        foreach ($runs as $i => $ips) {
            self::assertContains('127.0.0.1', $ips, "lookup #{$i} resolved nothing");
        }
        self::assertSame($before, count((array) $clients->getValue()), 'each lookup left a RemoteObject client behind');
    }
}
