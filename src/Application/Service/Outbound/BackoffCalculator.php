<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Service\Outbound;

use Semitexa\Core\Attribute\AsService;

#[AsService]
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

        // The growth is a float, and past about attempt 58 (30 s, x2) it exceeds
        // PHP_INT_MAX: the cast to int is then undefined (a negative number or 0).
        // A negative delay made random_int() below throw in the delivery worker,
        // and 0 meant a retry every second. So the cap comes before the cast, and
        // a float at or past it is never cast. The cap is at least 1 s: an endpoint's
        // max_backoff_seconds is a signed column nothing validates, and a negative
        // cap would make the delay, and with it the jitter range, negative.
        $maxBackoffSeconds = max(1, $maxBackoffSeconds);
        $growth = $initialBackoffSeconds * ($multiplier ** ($attemptCount - 1));
        $delay = $growth >= $maxBackoffSeconds ? $maxBackoffSeconds : (int) max(0.0, $growth);

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
