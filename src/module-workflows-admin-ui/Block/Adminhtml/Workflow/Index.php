<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Widget\Grid\Container;

class Index extends Container
{
    protected function _construct(): void
    {
        $this->_blockGroup = 'MageOS_WorkflowsAdminUi';
        $this->_controller = 'adminhtml_workflow';
        $this->_headerText = __('Workflows');
        $this->_addButtonLabel = __('New Workflow');
        parent::_construct();
    }
}
