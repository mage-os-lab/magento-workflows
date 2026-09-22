<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Absent on the new-workflow form (an empty button-data array is how a
 * ButtonProvider opts out — see Magento\Ui\Component\Control\Container).
 */
class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $workflowId = $this->getWorkflowId();
        if ($workflowId === null) {
            return [];
        }

        $deleteUrl = $this->getUrl('mageos_workflows/workflow/delete', ['workflow_id' => $workflowId]);
        $message = __('Are you sure you want to delete this workflow?');

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf("deleteConfirm('%s', '%s');", $message, $deleteUrl),
            'sort_order' => 20,
        ];
    }
}
