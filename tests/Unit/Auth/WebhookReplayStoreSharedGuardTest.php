<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore;

/**
 * The replay store's cross-worker introspection + the production-like guard.
 *
 * A `static`-array replay store is per-PROCESS: with N Swoole workers a
 * duplicate delivery on a different worker is never detected, so replay
 * protection is defeated by worker affinity. The worker-local store must
 * declare isShared()===false (so a consumer can guard, parity with the
 * platform-ui UiReplayStore contract) AND surface a boot diagnostic the first
 * time it is actually used in a production-like environment.
 */
final class WebhookReplayStoreSharedGuardTest extends TestCase
{
    private string|false $appEnvBefore = false;

    protected function setUp(): void
    {
        $this->appEnvBefore = getenv('APP_ENV');
        $this->resetRegisteredFlag();
    }

    protected function tearDown(): void
    {
        if ($this->appEnvBefore === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->appEnvBefore);
        }
        $this->resetRegisteredFlag();
    }

    #[Test]
    public function worker_local_store_declares_itself_not_shared(): void
    {
        $store = new InMemoryWebhookReplayStore();
        self::assertFalse($store->isShared(), 'A per-process static map is not cross-worker.');
        self::assertStringContainsString('worker-local', $store->diagnosticName());
    }

    #[Test]
    public function first_use_in_production_surfaces_a_boot_diagnostic(): void
    {
        putenv('APP_ENV=prod');
        $diagnostics = BootDiagnostics::begin();

        (new InMemoryWebhookReplayStore())->markIfFirstSeen('evt-1');

        $warnings = array_filter(
            $diagnostics->getWarnings(),
            static fn ($w): bool => $w->component === 'WebhookReplayStore',
        );
        self::assertNotSame([], $warnings, 'Worker-local replay store in prod must warn.');
        self::assertStringContainsString('defeated by worker affinity', reset($warnings)->message);
    }

    #[Test]
    public function non_production_use_stays_silent(): void
    {
        putenv('APP_ENV=test');
        $diagnostics = BootDiagnostics::begin();

        (new InMemoryWebhookReplayStore())->markIfFirstSeen('evt-2');

        $warnings = array_filter(
            $diagnostics->getWarnings(),
            static fn ($w): bool => $w->component === 'WebhookReplayStore',
        );
        self::assertSame([], $warnings, 'Dev/test is the sanctioned home of the in-memory store — no noise.');
    }

    private function resetRegisteredFlag(): void
    {
        $flag = new \ReflectionProperty(InMemoryWebhookReplayStore::class, 'registered');
        $flag->setValue(null, false);
        (new InMemoryWebhookReplayStore())->clear();
    }
}
