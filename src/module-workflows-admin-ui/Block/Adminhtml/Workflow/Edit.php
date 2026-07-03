<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Widget\Form\Container;

class Edit extends Container
{
    protected function _construct(): void
    {
        $this->_objectId = 'workflow_id';
        $this->_blockGroup = 'MageOS_WorkflowsAdminUi';
        $this->_controller = 'adminhtml_workflow';
        parent::_construct();

        $this->buttonList->update('save', 'label', __('Save Workflow'));

        if (!$this->getWorkflowId()) {
            $this->buttonList->remove('delete');
        }
    }

    public function getWorkflowId(): ?int
    {
        $id = (int) $this->getRequest()->getParam('workflow_id');
        return $id ?: null;
    }

    public function getHeaderText(): \Magento\Framework\Phrase
    {
        return $this->getWorkflowId() ? __('Edit Workflow') : __('New Workflow');
    }
}
