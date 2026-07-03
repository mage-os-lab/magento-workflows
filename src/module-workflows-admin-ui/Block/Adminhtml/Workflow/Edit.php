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
        } elseif ($this->_authorization->isAllowed('MageOS_Workflows::manual_run')) {
            $this->buttonList->add(
                'run_now',
                [
                    'label' => __('Run Now'),
                    'class' => 'action-secondary',
                    'onclick' => $this->getRunNowOnclick(),
                    'sort_order' => 30,
                ]
            );
        }
    }

    /**
     * Prompt for the target entity ID (plain window.prompt, v1 adminhtml) and navigate to the
     * Run controller; the URL builder already appends the adminhtml secret key, and extra
     * path params after it are still routed, so entity_id is appended client-side.
     */
    private function getRunNowOnclick(): string
    {
        $runUrl = $this->getUrl('mageos_workflows/workflow/run', ['workflow_id' => $this->getWorkflowId()]);
        $prompt = json_encode(
            (string) __('Enter the ID of the entity (e.g. order or customer ID) to run this workflow against:'),
            JSON_THROW_ON_ERROR
        );

        return "var entityId = window.prompt({$prompt}); "
            . "if (entityId !== null && entityId.trim() !== '') { "
            . "setLocation('{$runUrl}' + 'entity_id/' + encodeURIComponent(entityId.trim()) + '/'); }";
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
