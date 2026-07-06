<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

/**
 * Tally for one mass-decide run (docs/discovery/approval-gate.md §6): rows
 * whose gate did not opt into allow_bulk are SKIPPED and reported, never
 * silently decided; rows that lost a race (already decided/expired/orphaned
 * between grid load and submit) are reported separately from genuine
 * per-row failures.
 */
class MassDecideResult
{
    public function __construct(
        private int $decided = 0,
        private int $skippedNotBulk = 0,
        private int $alreadyDecided = 0,
        private int $failed = 0
    ) {
    }

    public function addDecided(): void
    {
        $this->decided++;
    }

    public function addSkippedNotBulk(): void
    {
        $this->skippedNotBulk++;
    }

    public function addAlreadyDecided(): void
    {
        $this->alreadyDecided++;
    }

    public function addFailed(): void
    {
        $this->failed++;
    }

    public function getDecided(): int
    {
        return $this->decided;
    }

    public function getSkippedNotBulk(): int
    {
        return $this->skippedNotBulk;
    }

    public function getAlreadyDecided(): int
    {
        return $this->alreadyDecided;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    /**
     * Merchant-facing summary — every outcome is named, nothing is silently
     * dropped (§6: "never silently decided").
     */
    public function toMessage(): string
    {
        $parts = [sprintf('%d decided', $this->decided)];
        if ($this->skippedNotBulk > 0) {
            $parts[] = sprintf('%d skipped (not bulk-enabled)', $this->skippedNotBulk);
        }
        if ($this->alreadyDecided > 0) {
            $parts[] = sprintf('%d already decided', $this->alreadyDecided);
        }
        if ($this->failed > 0) {
            $parts[] = sprintf('%d failed', $this->failed);
        }
        return implode(', ', $parts) . '.';
    }
}
