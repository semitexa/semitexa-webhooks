<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Configuration;

final readonly class WebhookConfig
{
    public function __construct(
        public int $defaultTimeoutSeconds = 30,
        public int $defaultMaxAttempts = 5,
        public int $defaultBackoffBaseSeconds = 10,
        public float $defaultBackoffMultiplier = 2.0,
        public int $defaultLeaseSeconds = 120,
        public int $defaultDedupeWindowSeconds = 86400,
        public int $retentionDays = 30,
    ) {}

    public static function fromEnvironment(): self
    {
        return new self(
            defaultTimeoutSeconds: (int) ($_ENV['WEBHOOK_TIMEOUT_SECONDS'] ?? 30),
            defaultMaxAttempts: (int) ($_ENV['WEBHOOK_MAX_ATTEMPTS'] ?? 5),
            defaultBackoffBaseSeconds: (int) ($_ENV['WEBHOOK_BACKOFF_BASE_SECONDS'] ?? 10),
            defaultBackoffMultiplier: (float) ($_ENV['WEBHOOK_BACKOFF_MULTIPLIER'] ?? 2.0),
            defaultLeaseSeconds: (int) ($_ENV['WEBHOOK_LEASE_SECONDS'] ?? 120),
            defaultDedupeWindowSeconds: (int) ($_ENV['WEBHOOK_DEDUPE_WINDOW_SECONDS'] ?? 86400),
            retentionDays: (int) ($_ENV['WEBHOOK_RETENTION_DAYS'] ?? 30),
        );
    }
}
