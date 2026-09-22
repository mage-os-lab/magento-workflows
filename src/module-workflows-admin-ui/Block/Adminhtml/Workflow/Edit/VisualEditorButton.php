<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Module\Manager;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Optional canvas module: the visual-editor entry from the form. Absent when
 * the module is disabled — admin-ui never depends on it. Managers reach the
 * ::manage editor controller; ::view-only admins get the read-only viewer (both
 * render the same mount; the React app and the write controllers gate editing on
 * ::manage independently).
 *
 * On the NEW-workflow form (no id yet) the button becomes "Create in visual
 * editor" and links to the editor with no workflow_id: the canvas bootstraps a
 * blank workflow and posts the finished thing through the same admin Save
 * controller, which creates the record. A ::view-only admin gets nothing there
 * — an unsaved workflow has nothing to view.
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
        if (!$this->moduleManager->isEnabled('MageOS_WorkflowsCanvas')) {
            return [];
        }

        $workflowId = $this->getWorkflowId();
        $canManage = $this->isAllowed('MageOS_Workflows::manage');

        if ($workflowId === null) {
            return $canManage
                ? $this->button(
                    __('Create in visual editor'),
                    $this->getUrl('mageos_workflows_canvas/canvas/edit')
                )
                : [];
        }

        $canvasRoute = $canManage
            ? 'mageos_workflows_canvas/canvas/edit'
            : 'mageos_workflows_canvas/canvas/view';

        return $this->button(
            __('Open in visual editor'),
            $this->getUrl($canvasRoute, ['workflow_id' => $workflowId])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function button(Phrase $label, string $url): array
    {
        return [
            'label' => $label,
            'class' => 'action-secondary',
            'on_click' => sprintf("location.href = '%s';", $url),
            'sort_order' => 50,
        ];
    }
}
