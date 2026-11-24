<?php

declare(strict_types=1);

namespace Semitexa\Webhooks\Auth\Contract;

/**
 * A replay store whose claim remembers who made it.
 *
 * {@see WebhookReplayStoreInterface::markIfFirstSeen()} answers "was I first"
 * once: a caller that claimed and then lost the answer (its worker died, the
 * connection dropped) or failed later and is tried again cannot tell its own
 * claim from another caller's. A second key that names the caller does not
 * close it: two writes are not one, and a store error between them leaves
 * either a caller that proceeds on a claim another holds, or a winner that is
 * dropped as its own replay (review, semitexa-webhooks#43).
 *
 * Here the claim and its owner are one write, so a retry proceeds only when
 * the store itself recorded that caller as the winner.
 *
 * Optional: a store that does not implement it keeps working, and the inbound
 * receiver falls back to two keys for it. Kept apart from
 * WebhookReplayStoreInterface so that a project's own store is not broken by a
 * new method.
 */
interface OwnedClaimStoreInterface
{
    /**
     * Claim $key for $owner, atomically.
     *
     * True when the key was absent and is now claimed by $owner, or is
     * already claimed by the same $owner (its own earlier attempt). False
     * when another owner holds it, or it was claimed with no owner
     * ({@see WebhookReplayStoreInterface::markIfFirstSeen()}).
     *
     * The first claim sets the expiry; a repeated claim by the owner does not
     * move it. $ttlSeconds is as in markIfFirstSeen(). $owner is at most 64
     * characters, the database store's column; the receiver passes a delivery
     * id, a UUID of 36.
     *
     * @throws OwnedClaimUnavailableException when the store cannot record an
     *         owner yet (a schema that predates it); nothing was written, and
     *         the caller may fall back to markIfFirstSeen()
     */
    public function claimFor(string $key, string $owner, ?int $ttlSeconds = null): bool;
}
