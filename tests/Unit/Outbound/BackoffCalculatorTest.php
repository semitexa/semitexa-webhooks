<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Service\Outbound\BackoffCalculator;

final class BackoffCalculatorTest extends TestCase
{
    private BackoffCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BackoffCalculator();
    }

    public function testFirstAttemptReturnsBaseDelay(): void
    {
        // attemptCount=0 returns initialBackoffSeconds without jitter
        $delay = $this->calculator->calculate(0, initialBackoffSeconds: 30);

        self::assertSame(30, $delay);
    }

    public function testExponentialGrowth(): void
    {
        // Run multiple times and check the average trend is growing
        $delays = [];
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $samples = [];
            for ($i = 0; $i < 20; $i++) {
                $samples[] = $this->calculator->calculate($attempt, 10, 3600, 2.0);
            }
            $delays[$attempt] = array_sum($samples) / count($samples);
        }

        // Each subsequent attempt should have a higher average delay
        self::assertGreaterThan($delays[1], $delays[2]);
        self::assertGreaterThan($delays[2], $delays[3]);
        self::assertGreaterThan($delays[3], $delays[4]);
    }

    public function testCapsAtMaxBackoff(): void
    {
        $delay = $this->calculator->calculate(100, 30, 3600, 2.0);

        self::assertLessThanOrEqual(3600, $delay);
    }

    /**
     * An endpoint with max_attempts of 60 or more reached attempts whose growth
     * (30 s x 2^(n-1)) exceeds PHP_INT_MAX. The cast gave a negative delay at
     * attempt 60, and random_int() threw in the delivery worker; at 70 it gave
     * 0, and the delivery was retried every second.
     */
    public function testLargeAttemptCountsStayAtTheCapWithItsJitter(): void
    {
        foreach ([58, 60, 64, 70, 1000] as $attempt) {
            $delay = $this->calculator->calculate($attempt);

            self::assertGreaterThanOrEqual(2700, $delay, "attempt {$attempt}: the 3600 s cap less its 25% jitter");
            self::assertLessThanOrEqual(3600, $delay, "attempt {$attempt}");
        }
    }

    public function testNoAttemptCountThrowsOrLeavesTheRange(): void
    {
        for ($attempt = 1; $attempt <= 200; $attempt++) {
            $delay = $this->calculator->calculate($attempt);

            self::assertGreaterThanOrEqual(1, $delay, "attempt {$attempt}");
            self::assertLessThanOrEqual(3600, $delay, "attempt {$attempt}");
        }
    }

    /** max_backoff_seconds is a signed column nothing validates; a cap of 0 or below is 1 s, never a throw. */
    public function testACapOfZeroOrBelowIsOneSecond(): void
    {
        self::assertSame(1, $this->calculator->calculate(5, 30, 0));
        self::assertSame(1, $this->calculator->calculate(5, 30, -100));
        self::assertSame(1, $this->calculator->calculate(70, 30, -100));
    }

    public function testMinimumDelayIsOne(): void
    {
        $delay = $this->calculator->calculate(0, 1, 3600, 1.0);

        self::assertGreaterThanOrEqual(1, $delay);
    }

    public function testNextAttemptAtReturnsFutureTimestamp(): void
    {
        $now = new \DateTimeImmutable();
        $next = $this->calculator->nextAttemptAt(1, 30);

        self::assertGreaterThan($now, $next);
    }

    public function testCustomMultiplier(): void
    {
        // With multiplier 3.0 and attempt 2: base * 3^1 = 30 * 3 = 90
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            $samples[] = $this->calculator->calculate(2, 30, 3600, 3.0);
        }
        $avg = array_sum($samples) / count($samples);

        // Average should be roughly 90 (±25% jitter range: 67-112)
        self::assertGreaterThan(50, $avg);
        self::assertLessThan(130, $avg);
    }
}
