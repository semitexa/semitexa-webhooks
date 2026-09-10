<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\Config;
use Semitexa\Webhooks\Configuration\WebhookConfig;

/**
 * Every default is written twice, so it is checked here.
 *
 * #[Config(default:)] is what the container applies; the declared property
 * default is what direct instantiation gets. They have to agree, and nothing but
 * this test makes them. The duplication exists because PHP needs an initializer
 * for `new self()` to produce a valid object, and the container reads the
 * attribute — there is no single place that can serve both.
 */
final class WebhookConfigDefaultsAgreeTest extends TestCase
{
    #[Test]
    public function the_attribute_default_matches_the_declared_default(): void
    {
        $reflection = new \ReflectionClass(WebhookConfig::class);
        $defaults = $reflection->getDefaultProperties();
        $checked = 0;

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Config::class);
            if ($attributes === []) {
                continue;
            }

            $configured = $attributes[0]->newInstance()->default;
            $declared = $defaults[$property->getName()] ?? null;

            self::assertSame(
                $configured,
                $declared,
                sprintf(
                    '%s: #[Config(default: %s)] but the property is declared = %s. '
                    . 'The container would apply one and `new WebhookConfig()` the other.',
                    $property->getName(),
                    var_export($configured, true),
                    var_export($declared, true),
                ),
            );
            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'nothing was compared — the attribute or the property set moved');
    }
}
