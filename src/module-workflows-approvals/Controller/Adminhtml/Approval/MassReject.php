<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * Mass Reject (docs/discovery/approval-gate.md §6). See MassApprove/AbstractMassDecide.
 */
class MassReject extends AbstractMassDecide
{
    protected function getDecision(): string
    {
        return ApprovalInterface::STATUS_REJECTED;
    }
}
