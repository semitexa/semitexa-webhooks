<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Configuration;

use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Configuration\WebhookConfig;

final class WebhookConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = WebhookConfig::withOverrides();

        self::assertSame(30, $config->defaultTimeoutSeconds);
        self::assertSame(5, $config->defaultMaxAttempts);
        self::assertSame(10, $config->defaultBackoffBaseSeconds);
        self::assertSame(2.0, $config->defaultBackoffMultiplier);
        self::assertSame(120, $config->defaultLeaseSeconds);
        self::assertSame(86400, $config->defaultDedupeWindowSeconds);
        self::assertSame(30, $config->retentionDays);
    }

    public function testCustomValues(): void
    {
        $config = WebhookConfig::withOverrides(
            defaultTimeoutSeconds: 60,
            defaultMaxAttempts: 10,
            retentionDays: 90,
        );

        self::assertSame(60, $config->defaultTimeoutSeconds);
        self::assertSame(10, $config->defaultMaxAttempts);
        self::assertSame(90, $config->retentionDays);
    }

    public function testFromEnvironmentUsesDefaults(): void
    {
        $config = WebhookConfig::fromEnvironment();

        self::assertSame(30, $config->defaultTimeoutSeconds);
        self::assertSame(5, $config->defaultMaxAttempts);
    }

    public function testFromEnvironmentReadsEnvVars(): void
    {
        $_ENV['WEBHOOK_TIMEOUT_SECONDS'] = '45';
        $_ENV['WEBHOOK_MAX_ATTEMPTS'] = '8';
        $_ENV['WEBHOOK_RETENTION_DAYS'] = '60';

        try {
            $config = WebhookConfig::fromEnvironment();

            self::assertSame(45, $config->defaultTimeoutSeconds);
            self::assertSame(8, $config->defaultMaxAttempts);
            self::assertSame(60, $config->retentionDays);
        } finally {
            unset(
                $_ENV['WEBHOOK_TIMEOUT_SECONDS'],
                $_ENV['WEBHOOK_MAX_ATTEMPTS'],
                $_ENV['WEBHOOK_RETENTION_DAYS'],
            );
        }
    }
}
