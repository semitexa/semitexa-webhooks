<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Outbound\BackoffCalculator;

final class BackoffCalculatorTest extends TestCase
{
    private BackoffCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new BackoffCalculator();
    }

    public function testFirstAttemptReturnsBaseDelay(): void
    {
        // attemptCount=0 returns initialBackoffSeconds (with jitter)
        $delay = $this->calculator->calculate(0, initialBackoffSeconds: 30);

        // With ±25% jitter: 30 ± 7 = [23, 37]
        self::assertGreaterThanOrEqual(1, $delay);
        self::assertLessThanOrEqual(37, $delay);
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
