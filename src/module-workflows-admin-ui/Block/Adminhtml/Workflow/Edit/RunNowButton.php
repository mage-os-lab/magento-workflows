<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Prompts for the target entity ID (plain window.prompt, v1 adminhtml) and
 * navigates to the Run controller. The URL builder already appends the adminhtml
 * secret key, and extra path params after it are still routed, so entity_id is
 * appended client-side.
 */
class RunNowButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if ($this->getWorkflowId() === null || !$this->isAllowed('MageOS_Workflows::manual_run')) {
            return [];
        }

        return [
            'label' => __('Run Now'),
            'class' => 'action-secondary',
            'on_click' => $this->getOnClick(),
            'sort_order' => 40,
        ];
    }

    private function getOnClick(): string
    {
        $runUrl = $this->getUrl('mageos_workflows/workflow/run', ['workflow_id' => $this->getWorkflowId()]);
        $prompt = json_encode(
            (string) __('Enter the ID of the entity (e.g. order or customer ID) to run this workflow against:'),
            JSON_THROW_ON_ERROR
        );

        return "var entityId = window.prompt({$prompt}); "
            . "if (entityId !== null && entityId.trim() !== '') { "
            . "location.href = '{$runUrl}' + 'entity_id/' + encodeURIComponent(entityId.trim()) + '/'; }";
    }
}
