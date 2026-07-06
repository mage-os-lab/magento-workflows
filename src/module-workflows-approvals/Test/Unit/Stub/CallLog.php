<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

/**
 * Ordered marker log shared between the fake connection and publisher so tests
 * can assert result-before-publish ordering (docs/discovery/approval-gate.md §4).
 */
class CallLog
{
    /** @var string[] */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
