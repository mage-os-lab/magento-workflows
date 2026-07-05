<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * Status filter/select options for the approvals grid (docs/discovery/approval-gate.md §6).
 */
class ApprovalStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => ApprovalInterface::STATUS_OPEN, 'label' => __('Open')],
            ['value' => ApprovalInterface::STATUS_APPROVED, 'label' => __('Approved')],
            ['value' => ApprovalInterface::STATUS_REJECTED, 'label' => __('Rejected')],
            ['value' => ApprovalInterface::STATUS_EXPIRED, 'label' => __('Expired')],
            ['value' => ApprovalInterface::STATUS_ORPHANED, 'label' => __('Orphaned')],
        ];
    }
}
