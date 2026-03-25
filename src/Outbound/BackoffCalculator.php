<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Outbound;

final class BackoffCalculator
{
    public function calculate(
        int $attemptCount,
        int $initialBackoffSeconds = 30,
        int $maxBackoffSeconds = 3600,
        float $multiplier = 2.0,
    ): int {
        if ($attemptCount <= 0) {
            return $initialBackoffSeconds;
        }

        $delay = (int) ($initialBackoffSeconds * ($multiplier ** ($attemptCount - 1)));

        // Add jitter: ±25%
        $jitter = (int) ($delay * 0.25);
        $delay += random_int(-$jitter, $jitter);

        return min(max($delay, 1), $maxBackoffSeconds);
    }

    public function nextAttemptAt(
        int $attemptCount,
        int $initialBackoffSeconds = 30,
        int $maxBackoffSeconds = 3600,
        float $multiplier = 2.0,
    ): \DateTimeImmutable {
        $delay = $this->calculate($attemptCount, $initialBackoffSeconds, $maxBackoffSeconds, $multiplier);
        return new \DateTimeImmutable("+{$delay} seconds");
    }
}
