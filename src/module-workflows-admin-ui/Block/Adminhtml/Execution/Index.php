<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Execution;

use Magento\Backend\Block\Widget\Grid\Container;

class Index extends Container
{
    protected function _construct(): void
    {
        $this->_blockGroup = 'MageOS_WorkflowsAdminUi';
        $this->_controller = 'adminhtml_execution';
        $this->_headerText = __('Workflow Executions');
        parent::_construct();
    }
}
