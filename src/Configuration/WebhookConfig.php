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
    protected int $defaultTimeoutSeconds;

    #[Config(env: 'WEBHOOK_MAX_ATTEMPTS', default: 5)]
    protected int $defaultMaxAttempts;

    #[Config(env: 'WEBHOOK_BACKOFF_BASE_SECONDS', default: 10)]
    protected int $defaultBackoffBaseSeconds;

    #[Config(env: 'WEBHOOK_BACKOFF_MULTIPLIER', default: 2.0)]
    protected float $defaultBackoffMultiplier;

    #[Config(env: 'WEBHOOK_LEASE_SECONDS', default: 120)]
    protected int $defaultLeaseSeconds;

    #[Config(env: 'WEBHOOK_DEDUPE_WINDOW_SECONDS', default: 86400)]
    protected int $defaultDedupeWindowSeconds;

    #[Config(env: 'WEBHOOK_RETENTION_DAYS', default: 30)]
    protected int $retentionDays;

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
        $config->defaultTimeoutSeconds       = $defaultTimeoutSeconds       ?? (int) ($_ENV['WEBHOOK_TIMEOUT_SECONDS'] ?? 30);
        $config->defaultMaxAttempts          = $defaultMaxAttempts          ?? (int) ($_ENV['WEBHOOK_MAX_ATTEMPTS'] ?? 5);
        $config->defaultBackoffBaseSeconds   = $defaultBackoffBaseSeconds   ?? (int) ($_ENV['WEBHOOK_BACKOFF_BASE_SECONDS'] ?? 10);
        $config->defaultBackoffMultiplier    = $defaultBackoffMultiplier    ?? (float) ($_ENV['WEBHOOK_BACKOFF_MULTIPLIER'] ?? 2.0);
        $config->defaultLeaseSeconds         = $defaultLeaseSeconds         ?? (int) ($_ENV['WEBHOOK_LEASE_SECONDS'] ?? 120);
        $config->defaultDedupeWindowSeconds  = $defaultDedupeWindowSeconds  ?? (int) ($_ENV['WEBHOOK_DEDUPE_WINDOW_SECONDS'] ?? 86400);
        $config->retentionDays               = $retentionDays               ?? (int) ($_ENV['WEBHOOK_RETENTION_DAYS'] ?? 30);
        return $config;
    }

    public static function fromEnvironment(): self
    {
        return self::withOverrides();
    }
}
