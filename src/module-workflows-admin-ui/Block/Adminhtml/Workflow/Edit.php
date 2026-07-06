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

        if ($this->_authorization->isAllowed('MageOS_Workflows::dry_run')) {
            $this->buttonList->add(
                'dry_run',
                [
                    'label' => __('Dry run'),
                    'class' => 'action-secondary',
                    'onclick' => $this->getDryRunOnclick(),
                    'sort_order' => 35,
                ]
            );
        }

        // Optional canvas module: "Open in visual editor" entry from the form.
        // Hidden when the module is absent — admin-ui never depends on it.
        // Managers reach the ::manage editor controller; ::view-only admins get
        // the read-only viewer (both render the same mount, the React app and
        // the write controllers gate editing on ::manage independently).
        if ($this->getWorkflowId() && $this->_moduleManager->isEnabled('MageOS_WorkflowsCanvas')) {
            $canvasRoute = $this->_authorization->isAllowed('MageOS_Workflows::manage')
                ? 'mageos_workflows_canvas/canvas/edit'
                : 'mageos_workflows_canvas/canvas/view';
            $canvasUrl = $this->getUrl($canvasRoute, ['workflow_id' => $this->getWorkflowId()]);
            $this->buttonList->add(
                'visual_editor',
                [
                    'label' => __('Open in visual editor'),
                    'class' => 'action-secondary',
                    'onclick' => "setLocation('{$canvasUrl}')",
                    'sort_order' => 40,
                ]
            );
        }
    }

    /**
     * Stash the currently edited (unsaved) definition/conditions/entity type so
     * the dry-run page can preview them without a save, then navigate there.
     */
    private function getDryRunOnclick(): string
    {
        $params = $this->getWorkflowId() ? ['workflow_id' => $this->getWorkflowId()] : [];
        $dryRunUrl = $this->getUrl('mageos_workflows/workflow/dryRun', $params);

        return "try { "
            . "var def = document.querySelector('[name=\"definition\"]'); "
            . "var cond = document.querySelector('[name=\"conditions_serialized\"]'); "
            . "var et = document.querySelector('[name=\"entity_type\"]'); "
            . "if (def) { window.sessionStorage.setItem('mageos_dryrun_definition', def.value); } "
            . "if (cond) { window.sessionStorage.setItem('mageos_dryrun_conditions', cond.value); } "
            . "if (et) { window.sessionStorage.setItem('mageos_dryrun_entity_type', et.value); } "
            . "} catch (e) {} "
            . "setLocation('{$dryRunUrl}');";
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
