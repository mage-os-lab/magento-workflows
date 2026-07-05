<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * Mass Approve (docs/discovery/approval-gate.md §6). All decision logic — the
 * allow_bulk gate, the cap, the per-row claims — lives in
 * Model\MassDecideProcessor; this controller only supplies the decision value.
 */
class MassApprove extends AbstractMassDecide
{
    protected function getDecision(): string
    {
        return ApprovalInterface::STATUS_APPROVED;
    }
}
