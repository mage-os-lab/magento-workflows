<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Module\Manager;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Optional canvas module: "Open in visual editor" entry from the form. Absent
 * when the module is disabled — admin-ui never depends on it. Managers reach the
 * ::manage editor controller; ::view-only admins get the read-only viewer (both
 * render the same mount; the React app and the write controllers gate editing on
 * ::manage independently).
 */
class VisualEditorButton extends GenericButton implements ButtonProviderInterface
{
    public function __construct(
        Context $context,
        private readonly Manager $moduleManager
    ) {
        parent::__construct($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $workflowId = $this->getWorkflowId();
        if ($workflowId === null || !$this->moduleManager->isEnabled('MageOS_WorkflowsCanvas')) {
            return [];
        }

        $canvasRoute = $this->isAllowed('MageOS_Workflows::manage')
            ? 'mageos_workflows_canvas/canvas/edit'
            : 'mageos_workflows_canvas/canvas/view';

        return [
            'label' => __('Open in visual editor'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "location.href = '%s';",
                $this->getUrl($canvasRoute, ['workflow_id' => $workflowId])
            ),
            'sort_order' => 50,
        ];
    }
}
