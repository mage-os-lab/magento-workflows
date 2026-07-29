<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Stashes the currently edited (unsaved) definition/conditions/entity type so the
 * dry-run page can preview them without a save, then navigates there.
 */
class DryRunButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->isAllowed('MageOS_Workflows::dry_run')) {
            return [];
        }

        return [
            'label' => __('Dry run'),
            'class' => 'action-secondary',
            'on_click' => $this->getOnClick(),
            'sort_order' => 30,
        ];
    }

    private function getOnClick(): string
    {
        $workflowId = $this->getWorkflowId();
        $params = $workflowId ? ['workflow_id' => $workflowId] : [];
        $dryRunUrl = $this->getUrl('mageos_workflows/workflow/dryRun', $params);

        return "try { "
            . "var def = document.querySelector('[name=\"definition\"]'); "
            . "var cond = document.querySelector('[name=\"conditions_serialized\"]'); "
            . "var et = document.querySelector('[name=\"entity_type\"]'); "
            . "if (def) { window.sessionStorage.setItem('mageos_dryrun_definition', def.value); } "
            . "if (cond) { window.sessionStorage.setItem('mageos_dryrun_conditions', cond.value); } "
            . "if (et) { window.sessionStorage.setItem('mageos_dryrun_entity_type', et.value); } "
            . "} catch (e) {} "
            . "location.href = '{$dryRunUrl}';";
    }
}
