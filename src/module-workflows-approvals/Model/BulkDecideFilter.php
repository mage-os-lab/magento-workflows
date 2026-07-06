<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * The allow_bulk gate for mass-decide (docs/discovery/approval-gate.md §6): a
 * task may be mass-decided only when (a) it is still open and (b) its gate's
 * config.allow_bulk snapshot is true. Enforced here server-side, per row —
 * never just a grid filter — so a crafted mass-action request cannot bulk
 * decide a gate that did not opt in.
 */
class BulkDecideFilter
{
    public function __construct(
        private readonly GateConfigReader $gateConfigReader
    ) {
    }

    /**
     * @return bool true only when the task is open AND its gate declared allow_bulk: true
     */
    public function isEligible(ApprovalInterface $task): bool
    {
        if ($task->getStatus() !== ApprovalInterface::STATUS_OPEN) {
            return false;
        }
        return $this->gateConfigReader->isBulkAllowed($task->getExecutionId(), $task->getStepKey());
    }
}
