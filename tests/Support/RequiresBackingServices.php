<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Support;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\OrmManager;

/**
 * The real MySQL and Redis these integration tests are about.
 *
 * Not configured (DB_HOST or REDIS_HOST unset: the suite run outside the
 * stack) is a skip. Configured and not answering is a failure: each test used
 * to skip there too, so a database the stack could not reach left the suite
 * green over everything these tests exist to prove.
 */
trait RequiresBackingServices
{
    private static function mysqlOrm(): OrmManager
    {
        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('DB_HOST not configured — integration test requires real MySQL');
        }

        try {
            $orm = ContainerFactory::get()->get(OrmManager::class);
            $orm->getAdapter()->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::fail("DB_HOST={$host} is configured, but MySQL does not answer: " . $e->getMessage());
        }

        return $orm;
    }

    private static function redisHost(): string
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('REDIS_HOST not configured — integration test requires real Redis');
        }

        return $host;
    }

    /** @param callable(): mixed $probe */
    private static function redisAnswers(string $host, callable $probe): void
    {
        try {
            $probe();
        } catch (\Throwable $e) {
            self::fail("REDIS_HOST={$host} is configured, but Redis does not answer: " . $e->getMessage());
        }
    }
}
