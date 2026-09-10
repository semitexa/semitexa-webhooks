<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Outbound;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Application\Db\MySQL\Repository\OutboundDeliveryRepository;
use Semitexa\Testing\Traits\BuildsContainerManagedObjects;

/**
 * The lock repository treats a duplicate key as "another worker won the race
 * for this lock" and falls through to its steal path. That branch must fire
 * for a duplicate key and NOTHING else: SQLSTATE 23000 also covers
 * foreign-key violations (MySQL 1451/1452), and taking the race branch for
 * one of those would hide a real schema failure behind a retry.
 *
 * The exception arrives in several shapes depending on the installed ORM —
 * the typed ConstraintViolationException, a raw PDOException, or either of
 * them wrapped — so all of them are pinned here.
 */
final class DuplicateKeyDetectionTest extends TestCase
{
    use BuildsContainerManagedObjects;

    #[Test]
    public function a_duplicate_key_is_recognized_in_every_exception_shape(): void
    {
        self::assertTrue(self::detect(self::pdoException('23000', 1062, "Duplicate entry 'evt-1' for key 'PRIMARY'")));

        // Wrapped by a generic write-engine rethrow.
        self::assertTrue(self::detect(new \RuntimeException(
            'write failed',
            0,
            self::pdoException('23000', 1062, "Duplicate entry 'evt-1' for key 'PRIMARY'"),
        )));

        // SQLite reports no vendor code — the message carries the detail.
        self::assertTrue(self::detect(self::pdoException(
            '23000',
            19,
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: webhook_outbox.idempotency_key',
        )));
    }

    #[Test]
    public function other_integrity_violations_are_not_treated_as_duplicates(): void
    {
        // A foreign-key failure is also SQLSTATE 23000, but it is a real
        // failure, not a lost race.
        self::assertFalse(self::detect(self::pdoException(
            '23000',
            1452,
            'Cannot add or update a child row: a foreign key constraint fails',
        )));

        self::assertFalse(self::detect(self::pdoException(
            '23000',
            1451,
            'Cannot delete or update a parent row: a foreign key constraint fails',
        )));

        self::assertFalse(self::detect(new \RuntimeException('some unrelated failure')));
    }

    private static function detect(\Throwable $e): bool
    {
        // The detector is an instance method but touches no state; the
        // repository's real dependencies are injected properties, so an
        // uninitialized instance is enough to exercise it.
        $repository = self::createWithDependencies(OutboundDeliveryRepository::class);
        $method = new \ReflectionMethod(OutboundDeliveryRepository::class, 'isDuplicateKeyException');

        return (bool) $method->invoke($repository, $e);
    }

    private static function pdoException(string $sqlState, int $driverCode, string $message): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$sqlState, $driverCode, $message];

        return $e;
    }
}
