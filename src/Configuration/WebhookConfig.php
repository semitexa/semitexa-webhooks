<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Configuration;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;

/**
 * Webhook subsystem configuration. Read once at worker boot and cached as a
 * readonly singleton via the framework's container.
 *
 * Scalar fields are populated via the framework's {@see Config} attribute —
 * the canonical channel for env-driven scalars on container-managed
 * services. Direct construction (`new WebhookConfig()`) and the back-compat
 * helper {@see fromEnvironment()} continue to read the same env vars and
 * fall back to the documented defaults.
 *
 * The named-argument override pattern remains supported via
 * {@see withOverrides()} — tests that pin a known retention window without
 * losing the other env-driven fields use that helper.
 */
#[AsService]
final class WebhookConfig
{
    #[Config(env: 'WEBHOOK_TIMEOUT_SECONDS', default: 30)]
    protected int $defaultTimeoutSeconds = 30;

    #[Config(env: 'WEBHOOK_MAX_ATTEMPTS', default: 5)]
    protected int $defaultMaxAttempts = 5;

    #[Config(env: 'WEBHOOK_BACKOFF_BASE_SECONDS', default: 10)]
    protected int $defaultBackoffBaseSeconds = 10;

    #[Config(env: 'WEBHOOK_BACKOFF_MULTIPLIER', default: 2.0)]
    protected float $defaultBackoffMultiplier = 2.0;

    #[Config(env: 'WEBHOOK_LEASE_SECONDS', default: 120)]
    protected int $defaultLeaseSeconds = 120;

    #[Config(env: 'WEBHOOK_DEDUPE_WINDOW_SECONDS', default: 86400)]
    protected int $defaultDedupeWindowSeconds = 86400;

    #[Config(env: 'WEBHOOK_RETENTION_DAYS', default: 30)]
    protected int $retentionDays = 30;


    public function getDefaultTimeoutSeconds(): int { return $this->defaultTimeoutSeconds; }
    public function getDefaultMaxAttempts(): int { return $this->defaultMaxAttempts; }
    public function getDefaultBackoffBaseSeconds(): int { return $this->defaultBackoffBaseSeconds; }
    public function getDefaultBackoffMultiplier(): float { return $this->defaultBackoffMultiplier; }
    public function getDefaultLeaseSeconds(): int { return $this->defaultLeaseSeconds; }
    public function getDefaultDedupeWindowSeconds(): int { return $this->defaultDedupeWindowSeconds; }
    public function getRetentionDays(): int { return $this->retentionDays; }

    public function __get(string $name): mixed
    {
        // Backwards-compatible read-only access for callers that still read
        // the public-style property names. The container injects via
        // protected properties + #[Config], so callers reaching `$config->retentionDays`
        // hit this magic getter and receive the resolved value.
        return match ($name) {
            'defaultTimeoutSeconds'      => $this->defaultTimeoutSeconds,
            'defaultMaxAttempts'         => $this->defaultMaxAttempts,
            'defaultBackoffBaseSeconds'  => $this->defaultBackoffBaseSeconds,
            'defaultBackoffMultiplier'   => $this->defaultBackoffMultiplier,
            'defaultLeaseSeconds'        => $this->defaultLeaseSeconds,
            'defaultDedupeWindowSeconds' => $this->defaultDedupeWindowSeconds,
            'retentionDays'              => $this->retentionDays,
            default => throw new \InvalidArgumentException("Unknown WebhookConfig field: {$name}"),
        };
    }

    /**
     * Construct a config with explicit scalar overrides — used by tests that
     * pin specific values. Fields not overridden read from the environment
     * (or fall back to the documented defaults) the same way the
     * container-built singleton does.
     */
    public static function withOverrides(
        ?int $defaultTimeoutSeconds = null,
        ?int $defaultMaxAttempts = null,
        ?int $defaultBackoffBaseSeconds = null,
        ?float $defaultBackoffMultiplier = null,
        ?int $defaultLeaseSeconds = null,
        ?int $defaultDedupeWindowSeconds = null,
        ?int $retentionDays = null,
    ): self {
        $config = new self();
        if ($defaultTimeoutSeconds !== null)      $config->defaultTimeoutSeconds      = $defaultTimeoutSeconds;
        if ($defaultMaxAttempts !== null)         $config->defaultMaxAttempts         = $defaultMaxAttempts;
        if ($defaultBackoffBaseSeconds !== null)  $config->defaultBackoffBaseSeconds  = $defaultBackoffBaseSeconds;
        if ($defaultBackoffMultiplier !== null)   $config->defaultBackoffMultiplier   = $defaultBackoffMultiplier;
        if ($defaultLeaseSeconds !== null)         $config->defaultLeaseSeconds         = $defaultLeaseSeconds;
        if ($defaultDedupeWindowSeconds !== null) $config->defaultDedupeWindowSeconds = $defaultDedupeWindowSeconds;
        if ($retentionDays !== null)              $config->retentionDays              = $retentionDays;

        return $config;
    }

    /**
     * Read the environment explicitly, because nothing else here does.
     *
     * This used to be `withOverrides()` with no arguments, relying on a
     * constructor to pull $_ENV. The container never calls that constructor — it
     * builds container-managed classes with newInstanceWithoutConstructor() — so
     * under the container these values came from #[Config] and the constructor
     * was a second, unused implementation of the same thing. It is now one
     * implementation per caller: #[Config] for the container, this for anyone
     * asking for an env-derived config directly.
     */
    public static function fromEnvironment(): self
    {
        $int = static fn(string $key, int $fallback): int => isset($_ENV[$key]) ? (int) $_ENV[$key] : $fallback;

        return self::withOverrides(
            defaultTimeoutSeconds: $int('WEBHOOK_TIMEOUT_SECONDS', 30),
            defaultMaxAttempts: $int('WEBHOOK_MAX_ATTEMPTS', 5),
            defaultBackoffBaseSeconds: $int('WEBHOOK_BACKOFF_BASE_SECONDS', 10),
            defaultBackoffMultiplier: isset($_ENV['WEBHOOK_BACKOFF_MULTIPLIER'])
                ? (float) $_ENV['WEBHOOK_BACKOFF_MULTIPLIER']
                : 2.0,
            defaultLeaseSeconds: $int('WEBHOOK_LEASE_SECONDS', 120),
            defaultDedupeWindowSeconds: $int('WEBHOOK_DEDUPE_WINDOW_SECONDS', 86400),
            retentionDays: $int('WEBHOOK_RETENTION_DAYS', 30),
        );
    }
}
