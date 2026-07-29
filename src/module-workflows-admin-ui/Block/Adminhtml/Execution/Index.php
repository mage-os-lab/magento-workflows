<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Execution;

use Magento\Backend\Block\Widget\Container;

/**
 * Page container for the executions grid: header text only. Deliberately NOT
 * Widget\Grid\Container — that base class synthesizes a legacy
 * "{blockGroup}\Block\{controller}\Grid" child by convention, and this page
 * renders the mageos_workflow_executions_listing uiComponent instead; the
 * phantom class makes the whole page throw "Invalid block type" on a real
 * install. Same reasoning (and same fix) as Workflow\Index.
 */
class Index extends Container
{
    protected function _construct(): void
    {
        $this->_headerText = __('Workflow Executions');
        parent::_construct();
    }
}
