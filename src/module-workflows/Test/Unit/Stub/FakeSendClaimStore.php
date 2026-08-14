<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;

/**
 * In-memory SendClaimStoreInterface stand-in for unit tests.
 *
 * It models the ONE property the production table provides and a cache never
 * did: claim() is atomic — the first caller for a (scope, key) wins and every
 * later caller is told "already claimed" — and nothing but release() takes a
 * claim away. Tests use it to pin claim-before-send ordering (via $events),
 * redelivery skips, and the crash window (seed a `claimed` row with
 * seedClaim(), then run the action as the redelivery would).
 *
 * failClaim / failFind / failConfirm / failRelease inject infrastructure
 * errors so the "store cannot answer" paths are exercised too.
 */
class FakeSendClaimStore implements SendClaimStoreInterface
{
    /** @var array<string, array{status: string, claimed_at: ?string, sent_at: ?string}> */
    public array $rows = [];

    /** @var array<int, string> chronological log: claimed / claim_denied / confirmed / released / found */
    public array $events = [];

    public ?\Exception $failClaim = null;
    public ?\Exception $failFind = null;
    public ?\Exception $failConfirm = null;
    public ?\Exception $failRelease = null;

    public function claim(string $scope, string $dedupeKey): bool
    {
        if ($this->failClaim !== null) {
            throw $this->failClaim;
        }
        $key = $this->key($scope, $dedupeKey);
        if (isset($this->rows[$key])) {
            $this->events[] = 'claim_denied';
            return false;
        }
        $this->rows[$key] = [
            'status' => self::STATUS_CLAIMED,
            'claimed_at' => '2026-01-01 00:00:00',
            'sent_at' => null,
        ];
        $this->events[] = 'claimed';
        return true;
    }

    public function confirm(string $scope, string $dedupeKey): void
    {
        if ($this->failConfirm !== null) {
            throw $this->failConfirm;
        }
        $key = $this->key($scope, $dedupeKey);
        if (isset($this->rows[$key])) {
            $this->rows[$key]['status'] = self::STATUS_SENT;
            $this->rows[$key]['sent_at'] = '2026-01-01 00:00:01';
        }
        $this->events[] = 'confirmed';
    }

    public function release(string $scope, string $dedupeKey): void
    {
        if ($this->failRelease !== null) {
            throw $this->failRelease;
        }
        unset($this->rows[$this->key($scope, $dedupeKey)]);
        $this->events[] = 'released';
    }

    public function find(string $scope, string $dedupeKey): ?array
    {
        if ($this->failFind !== null) {
            throw $this->failFind;
        }
        $this->events[] = 'found';
        return $this->rows[$this->key($scope, $dedupeKey)] ?? null;
    }

    /**
     * Plant a pre-existing claim, as a crashed earlier attempt (or another
     * consumer that got there first) would have left behind.
     */
    public function seedClaim(string $scope, string $dedupeKey, string $status = self::STATUS_CLAIMED): void
    {
        $this->rows[$this->key($scope, $dedupeKey)] = [
            'status' => $status,
            'claimed_at' => '2026-01-01 00:00:00',
            'sent_at' => $status === self::STATUS_SENT ? '2026-01-01 00:00:01' : null,
        ];
    }

    public function statusOf(string $scope, string $dedupeKey): ?string
    {
        return $this->rows[$this->key($scope, $dedupeKey)]['status'] ?? null;
    }

    private function key(string $scope, string $dedupeKey): string
    {
        return $scope . ':' . $dedupeKey;
    }
}
