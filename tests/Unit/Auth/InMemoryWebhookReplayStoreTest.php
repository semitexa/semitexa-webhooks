<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Auth\InMemoryWebhookReplayStore;

/**
 * Unit tests for InMemoryWebhookReplayStore — pin the atomic
 * markIfFirstSeen contract + TTL behavior.
 *
 * Concurrent-coroutine atomicity is covered by tests/Runtime/ConcurrentCoroutineIsolationTest.
 * This file pins the single-threaded behavior that backings inherit from
 * the contract.
 */
final class InMemoryWebhookReplayStoreTest extends TestCase
{
    private InMemoryWebhookReplayStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryWebhookReplayStore();
        $this->store->clear();
    }

    protected function tearDown(): void
    {
        $this->store->clear();
    }

    #[Test]
    public function first_markIfFirstSeen_returns_true(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('first'));
    }

    #[Test]
    public function second_markIfFirstSeen_for_same_key_returns_false(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('replay'));
        self::assertFalse($this->store->markIfFirstSeen('replay'));
    }

    #[Test]
    public function different_keys_are_independent(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('alpha'));
        self::assertTrue($this->store->markIfFirstSeen('beta'));
    }

    #[Test]
    public function seen_returns_false_before_first_mark(): void
    {
        self::assertFalse($this->store->seen('not-yet'));
    }

    #[Test]
    public function seen_returns_true_after_first_mark(): void
    {
        $this->store->markIfFirstSeen('x');
        self::assertTrue($this->store->seen('x'));
    }

    #[Test]
    public function legacy_markSeen_remains_callable_for_compat(): void
    {
        $this->store->markSeen('legacy-key');
        self::assertTrue($this->store->seen('legacy-key'));
        self::assertFalse(
            $this->store->markIfFirstSeen('legacy-key'),
            'after a legacy markSeen, markIfFirstSeen must still report not-first',
        );
    }

    #[Test]
    public function ttl_zero_is_treated_as_immediate_expiry(): void
    {
        // A 0-second TTL means the entry is already expired by the time we check.
        // The store treats expired entries as not-seen on the next touch
        // (lazy expiry), so a second call after a sleep wins.
        self::assertTrue($this->store->markIfFirstSeen('ttl-zero', 0));
        sleep(1); // give time() a chance to tick past the expiry
        self::assertTrue($this->store->markIfFirstSeen('ttl-zero', 0), 'expired key must be reclaimable');
    }

    #[Test]
    public function null_ttl_persists_for_the_lifetime_of_the_store(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('forever', null));
        self::assertFalse($this->store->markIfFirstSeen('forever', null));
    }

    #[Test]
    public function ttl_window_blocks_replay_within_window_and_releases_after(): void
    {
        self::assertTrue($this->store->markIfFirstSeen('window', 1));
        self::assertFalse($this->store->markIfFirstSeen('window', 1), 'within window');
        sleep(2);
        self::assertTrue($this->store->markIfFirstSeen('window', 1), 'after window');
    }

    #[Test]
    public function clear_wipes_every_key(): void
    {
        $this->store->markIfFirstSeen('a');
        $this->store->markIfFirstSeen('b');
        $this->store->clear();
        self::assertFalse($this->store->seen('a'));
        self::assertTrue($this->store->markIfFirstSeen('a'), 'after clear, key reclaimable');
    }
}
