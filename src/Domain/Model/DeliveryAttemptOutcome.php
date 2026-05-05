<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Domain\Model;

use Semitexa\Webhooks\Domain\Enum\OutboundStatus;

/**
 * Result of a single processOne() iteration of the outbound delivery worker.
 *
 * Returned (instead of being only logged) so tests and callers can drive the
 * worker one attempt at a time and inspect what happened — without hooking
 * into log output or the attempt-history repository.
 *
 * idleNoDeliveryDue is true when the worker had nothing to claim — exposed
 * cleanly so a test loop can stop iterating without sleeping.
 */
final readonly class DeliveryAttemptOutcome
{
    public function __construct(
        public bool $idleNoDeliveryDue,
        public ?string $deliveryId,
        public ?OutboundStatus $newStatus,
        public ?TransportResult $transportResult,
        public ?int $attemptNumber,
        public ?string $reason = null,
    ) {}

    public static function idle(): self
    {
        return new self(true, null, null, null, null, 'no-due-delivery');
    }
}
