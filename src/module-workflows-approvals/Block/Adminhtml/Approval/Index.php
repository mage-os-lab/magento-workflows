<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Block\Adminhtml\Approval;

use Magento\Backend\Block\Widget\Container;

/**
 * Page container for the approvals grid: header text only. Deliberately NOT
 * Widget\Grid\Container — that base class synthesizes a legacy
 * "{blockGroup}\Block\{controller}\Grid" child by convention, and this page
 * renders the mageos_workflow_approvals_listing uiComponent instead; the
 * phantom class makes the whole page throw "Invalid block type" on a real
 * install. Same reasoning (and same fix) as
 * MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow\Index.
 */
class Index extends Container
{
    protected function _construct(): void
    {
        $this->_headerText = __('Approval Tasks');
        parent::_construct();
    }
}
