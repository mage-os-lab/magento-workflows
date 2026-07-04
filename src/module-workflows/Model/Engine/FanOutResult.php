<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

/**
 * Outcome of a trigger-level fan-out expansion (F1, docs/discovery/
 * implementation/04-fan-out.md). The single NotifierResult the notifier
 * reports records these counts as response data:
 *
 *  - dispatched: children for which an execution row was created
 *  - skipped:    children that threw, whose target no longer exists, or that a
 *                per-child guard (debounce, suppression, scope) collapsed — a
 *                redelivered storm re-expands into all-skipped, which is how
 *                the crash story stays idempotent (discovery §3)
 *  - truncated:  true when the relation resolved more targets than the
 *                effective cap allowed, so the tail was dropped (never silent —
 *                logged and surfaced here)
 */
class FanOutResult
{
    public function __construct(
        private readonly int $dispatched,
        private readonly int $skipped,
        private readonly bool $truncated
    ) {
    }

    public function getDispatched(): int
    {
        return $this->dispatched;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return array{dispatched: int, skipped: int, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'dispatched' => $this->dispatched,
            'skipped' => $this->skipped,
            'truncated' => $this->truncated,
        ];
    }
}
