<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Application\Db\MySQL\Model;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Metadata\HasColumnReferences;

/**
 * Production replay-store table model used by MySqlWebhookReplayStore.
 *
 * `replay_key` is the natural primary key — every replay entry is uniquely
 * identified by the receiver-supplied dedupe key (typically a hash of
 * provider event id + receiver name). No surrogate id is needed; the
 * uniqueness constraint AND atomic-claim correctness both rely on the
 * primary-key uniqueness guarantee.
 *
 * `expires_at` is nullable: callers that pass `?int $ttlSeconds = null`
 * to MySqlWebhookReplayStore::markIfFirstSeen() store NULL for "never
 * expires"; callers that pass a positive int store an absolute datetime.
 *
 * Expired rows remain blocking until cleanupExpired() removes them. This
 * is safer than delete-then-insert (which is non-atomic and could
 * re-introduce a replay race) and matches the production-store contract:
 * operators run cleanupExpired() periodically; the framework never relies
 * on lazy expiry for correctness.
 */
#[FromTable(name: 'webhook_replay_keys')]
#[Index('expires_at')]
final readonly class WebhookReplayKeyResourceModel
{
    use HasColumnReferences;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(name: 'replay_key', type: MySqlType::Varchar, length: 191)]
        public string $replayKey,

        #[Column(name: 'first_seen_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $firstSeenAt,

        #[Column(name: 'expires_at', type: MySqlType::Datetime, nullable: true)]
        public ?\DateTimeImmutable $expiresAt,
    ) {}
}
