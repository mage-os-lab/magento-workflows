<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\RunNowModal;

/**
 * Opens the "Run Now" modal (RunNowModal + run-now-modal.js), which asks for the
 * target entity ID with a recent-entity picker — this button used to ask with a
 * window.prompt. The on_click stays a one-liner that raises one custom event on
 * the modal container; the RequireJS module owns the behaviour, including
 * building the Run URL, so nothing about the dispatch lives in inline script.
 *
 * The visibility gates are the modal's gates too (saved workflow + the dedicated
 * manual-run ACL), so the button never renders without the container it opens.
 */
class RunNowButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if ($this->getWorkflowId() === null || !$this->isAllowed(RunNowModal::ACL_MANUAL_RUN)) {
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
        return sprintf("jQuery('#%s').trigger('mageos:open-run-now');", RunNowModal::CONTAINER_ID);
    }
}
