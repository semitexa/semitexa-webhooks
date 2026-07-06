<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Environment;
use Semitexa\Core\Lifecycle\TestStateResetRegistry;
use Semitexa\Webhooks\Auth\Contract\WebhookReplayStoreInterface;

/**
 * Process-local replay/idempotency store. Backed by a static array so the
 * worker keeps the seen set across requests (which is the whole point — replay
 * detection has to outlive a single request).
 *
 * Production deployments will override the binding with a DB/Redis store; in
 * tests this default is used directly so each test process has predictable
 * isolation via clear().
 *
 * NOT registered with PerRequestStateRegistry — that registry is for
 * per-request state. A replay store is per-WORKER state by design.
 *
 * Self-registers with TestStateResetRegistry on first markSeen() so test
 * setUp can wipe it via TestStateResetRegistry::resetAllForTesting()
 * alongside the demo grant stores. The framework never calls the test
 * registry, so the per-worker survival contract is preserved.
 */
#[SatisfiesServiceContract(of: WebhookReplayStoreInterface::class)]
final class InMemoryWebhookReplayStore implements WebhookReplayStoreInterface
{
    private const REGISTRY_NAME = 'in_memory_webhook_replay_store';

    /**
     * Map: key → expires_at unix timestamp, or PHP_INT_MAX for "never".
     *
     * Expired entries are treated as not-seen on the NEXT touch (lazy
     * expiry). This avoids an unbounded growing set when the caller passes
     * a replay window — the entry self-collects on the next markIfFirstSeen()
     * / seen() against the same key. For in-memory tests and demos, that
     * is fine; production deployments use a real Redis or DB store with
     * native expiry.
     *
     * @var array<string, int>
     */
    private static array $seen = [];

    private static bool $registered = false;

    public function seen(string $key): bool
    {
        if (!isset(self::$seen[$key])) {
            return false;
        }
        if (self::$seen[$key] <= time()) {
            unset(self::$seen[$key]);
            return false;
        }
        return true;
    }

    public function markSeen(string $key): void
    {
        self::ensureRegistered();
        self::$seen[$key] = PHP_INT_MAX;
    }

    /**
     * Atomic claim — see contract docstring. The implementation is a single
     * PHP statement combining isset() existence check + assignment, which
     * Swoole coroutines cannot preempt mid-statement. So in-process
     * concurrent callers get safe one-winner semantics.
     *
     * If $ttlSeconds is provided, the entry expires after that many seconds
     * and a later call with the same key will succeed again.
     */
    public function markIfFirstSeen(string $key, ?int $ttlSeconds = null): bool
    {
        self::ensureRegistered();
        $now = time();
        // Lazy expiry: a stale entry counts as "free" so the next caller wins.
        if (isset(self::$seen[$key]) && self::$seen[$key] > $now) {
            return false;
        }
        self::$seen[$key] = $ttlSeconds !== null ? $now + $ttlSeconds : PHP_INT_MAX;
        return true;
    }

    public function clear(): void
    {
        self::$seen = [];
    }

    public function isShared(): bool
    {
        // A `static` array is per-PROCESS: each of the N Swoole workers keeps
        // its own seen-set, so a duplicate delivery on a different worker is
        // never detected. Not shared.
        return false;
    }

    public function diagnosticName(): string
    {
        return 'in-memory (worker-local)';
    }

    private static function ensureRegistered(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        // First actual use in a production-like environment is a
        // misconfiguration worth surfacing: replay protection on a
        // worker-local store is defeated by worker affinity, so a replayed
        // (or normally at-least-once redelivered) webhook that hashes to a
        // different worker is processed twice. Production must bind the
        // Redis/MySql store. Loud at boot, never a silent security downgrade.
        $env = strtolower(trim((string) Environment::getEnvValue('APP_ENV', 'prod')));
        if ($env === 'prod' || $env === 'production') {
            BootDiagnostics::current()->invalidUsage(
                'WebhookReplayStore',
                'the worker-local in-memory webhook replay store is active in a production-like '
                . 'environment (APP_ENV=' . $env . '); replay/idempotency protection is NOT '
                . 'shared across Swoole workers and is defeated by worker affinity. Bind '
                . 'RedisWebhookReplayStore or MySqlWebhookReplayStore.',
            );
        }

        TestStateResetRegistry::register(
            self::REGISTRY_NAME,
            static function (): void {
                self::$seen = [];
            },
        );
    }
}
