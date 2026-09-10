<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Webhooks\Configuration\WebhookConfig;

/**
 * A partial override keeps the environment for everything it does not name.
 *
 * That is what withOverrides() promises in its docblock, and for a while it
 * stopped being true: removing the constructor left `new self()` producing the
 * declared property defaults, so pinning one field quietly reset the other six
 * to their defaults even where the environment said otherwise. Raised in
 * review of semitexa-webhooks#26.
 */
final class WebhookConfigOverrideEnvironmentTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['WEBHOOK_TIMEOUT_SECONDS', 'WEBHOOK_RETENTION_DAYS', 'WEBHOOK_MAX_ATTEMPTS'] as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
        }
        $_ENV['WEBHOOK_TIMEOUT_SECONDS'] = '77';
        $_ENV['WEBHOOK_MAX_ATTEMPTS'] = '9';
        unset($_ENV['WEBHOOK_RETENTION_DAYS']);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    #[Test]
    public function pinning_one_field_leaves_the_others_reading_the_environment(): void
    {
        $config = WebhookConfig::withOverrides(retentionDays: 7);

        self::assertSame(7, $config->getRetentionDays(), 'the pinned field wins');
        self::assertSame(77, $config->getDefaultTimeoutSeconds(), 'an unpinned field still comes from the environment');
        self::assertSame(9, $config->getDefaultMaxAttempts());
    }

    #[Test]
    public function an_unset_variable_falls_back_to_the_documented_default(): void
    {
        self::assertSame(30, WebhookConfig::fromEnvironment()->getRetentionDays());
    }
}
