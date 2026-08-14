<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Idempotency;

/**
 * The action-facing half of the durable send-once guard: SendClaimStoreInterface
 * owns the row, this owns the POLICY every caller must apply identically —
 * when to release a claim, when to keep it, and how to describe one somebody
 * else already holds. Two actions send unrecallable mail today
 * (notify.email, order.send_email) and more will; the policy belongs in one
 * place, not copy-pasted per action where one copy will eventually drift into
 * "release on any failure" and re-open the double send.
 *
 * Usage inside an action:
 *
 *     if (!$guard->claim($this->getCode(), $key)) {          // throws on infra
 *         return ActionResult::skipped($guard->describeClaim($this->getCode(), $key));
 *     }
 *     ... build the message; on failure: $guard->release(...); return failure ...
 *     ... hand it to the transport; on failure: KEEP the claim, terminal failure ...
 *     $guard->confirm($this->getCode(), $key);
 *
 * The asymmetry is the point: release() only for failures that provably
 * precede the side effect, because a released claim means the next delivery
 * sends. Everything after the transport call keeps its claim — see
 * SendClaimStoreInterface for why "may not have been sent" beats "may have
 * been sent twice" for email.
 */
class SendOnceGuard
{
    public function __construct(
        private readonly SendClaimStoreInterface $claimStore
    ) {
    }

    /**
     * Take the claim for (scope, dedupeKey) before the side effect.
     *
     * @return bool true = this caller may proceed; false = already claimed
     * @throws \Exception when the store cannot answer — callers must park the
     *         step rather than sending under an unanswered guard.
     */
    public function claim(string $scope, string $dedupeKey): bool
    {
        return $this->claimStore->claim($scope, $dedupeKey);
    }

    /**
     * Human-readable reason for a skip caused by an existing claim, phrased
     * from the claim's status because the two cases mean different things:
     *  - `sent`: it provably reached the transport; nothing to do.
     *  - `claimed`: an earlier attempt died between claiming and confirming,
     *    so the message may never have gone out. Say that, rather than
     *    asserting a send that may not have happened.
     * A vanished row (pruned, or released by a racing retry) falls back to the
     * neutral wording: the claim answer was still "somebody has this".
     */
    public function describeClaim(string $scope, string $dedupeKey): string
    {
        $claim = null;
        try {
            $claim = $this->claimStore->find($scope, $dedupeKey);
        } catch (\Exception $e) {
            // Diagnostics only: a failed lookup must never turn a correct skip
            // into a failure, and never into a send.
            $claim = null;
        }

        $status = $claim['status'] ?? null;
        if ($status === SendClaimStoreInterface::STATUS_SENT) {
            return sprintf(
                'Duplicate delivery suppressed (this step already sent%s)',
                isset($claim['sent_at']) ? ' at ' . $claim['sent_at'] . ' UTC' : ''
            );
        }
        if ($status === SendClaimStoreInterface::STATUS_CLAIMED) {
            return sprintf(
                'Duplicate delivery suppressed (a previous attempt claimed this send%s but never confirmed it; '
                . 'it may never have gone out — check and resend manually if needed)',
                isset($claim['claimed_at']) ? ' at ' . $claim['claimed_at'] . ' UTC' : ''
            );
        }
        return 'Duplicate delivery suppressed (already claimed for this step)';
    }

    /**
     * Release a claim for a side effect that provably did NOT happen, so a
     * redelivery can retry it. Best-effort: a failure here leaves the claim in
     * place, which is the safe direction (at worst a legitimate retry skips).
     */
    public function release(string $scope, string $dedupeKey): void
    {
        try {
            $this->claimStore->release($scope, $dedupeKey);
        } catch (\Exception $e) {
            // Swallowed deliberately — see the docblock.
        }
    }

    /**
     * Upgrade a held claim to `sent`. Operator bookkeeping only: the side
     * effect already happened, so a failure here costs nothing but a vaguer
     * skip reason on some future redelivery.
     */
    public function confirm(string $scope, string $dedupeKey): void
    {
        try {
            $this->claimStore->confirm($scope, $dedupeKey);
        } catch (\Exception $e) {
            // Swallowed deliberately — see the docblock.
        }
    }
}
