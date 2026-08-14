<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Idempotency;

/**
 * Durable at-most-once claim store for side effects that CANNOT be undone or
 * detected afterwards — email being the canonical case (docs/08 crash safety).
 *
 * Why a table and not a cache entry: the guard it replaces was a
 * CacheInterface load-then-save, which fails in three separate ways —
 *  - it is not atomic (load, then save: two concurrent consumers can both
 *    read "absent" and both send);
 *  - the default file/local cache backend is PER NODE, so a two-consumer
 *    cluster has two independent guards;
 *  - `cache:flush`, a Redis restart, or plain LRU eviction erases it, after
 *    which every parked redelivery re-sends.
 * A unique index answers all three: the claim IS the INSERT, one row wins,
 * and the row outlives any cache lifetime.
 *
 * Contract, and the trade it encodes:
 *  1. claim() runs BEFORE the side effect. A duplicate key means somebody
 *     already claimed this (scope, dedupe key) — the caller must NOT send.
 *  2. confirm() is called after the side effect provably completed. It only
 *     upgrades the row's status for operator diagnosis; it is never the guard.
 *  3. release() is called ONLY when the side effect provably did not happen
 *     (a validation/transport-build failure BEFORE the send call was entered),
 *     so a genuine retry can still send.
 * A crash between claim() and confirm() therefore leaves a `claimed` row: the
 * mail may or may not have gone out, and the redelivery skips. That is the
 * deliberate trade for email — a possibly-unsent message an operator can
 * resend beats a duplicate one they cannot recall — and callers must report it
 * honestly in the skip reason rather than claiming "already sent".
 *
 * The scope is the claiming action's code (e.g. "notify.email"), so two
 * different actions can never collide on one dedupe key, and an operator
 * reading the table can tell what took the claim.
 */
interface SendClaimStoreInterface
{
    /**
     * Side effect attempted, outcome unknown (claimed, never confirmed).
     */
    public const STATUS_CLAIMED = 'claimed';

    /**
     * Side effect completed and reported success.
     */
    public const STATUS_SENT = 'sent';

    /**
     * Atomically claim (scope, dedupeKey) for a one-time side effect.
     *
     * @return bool true when THIS caller took the claim and may proceed;
     *              false when the claim already existed (do not send).
     * @throws \Exception on infrastructure failure — an unanswered claim
     *         question must park the step, never fall through to the send.
     */
    public function claim(string $scope, string $dedupeKey): bool;

    /**
     * Mark a held claim as confirmed-sent. Best-effort bookkeeping: a failure
     * here must not undo the send, and the claim stays either way.
     */
    public function confirm(string $scope, string $dedupeKey): void;

    /**
     * Drop a claim because the side effect provably did NOT happen, so a
     * retry may take it again.
     */
    public function release(string $scope, string $dedupeKey): void;

    /**
     * The claim row for (scope, dedupeKey), or null when there is none.
     * Used to phrase an honest skip reason: `sent` = it definitely went out,
     * `claimed` = a previous attempt died mid-send and nobody confirmed it.
     *
     * @return array{status: string, claimed_at: ?string, sent_at: ?string}|null
     */
    public function find(string $scope, string $dedupeKey): ?array;
}
