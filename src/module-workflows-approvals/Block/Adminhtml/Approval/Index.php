<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Block\Adminhtml\Approval;

use Magento\Backend\Block\Widget\Grid\Container;

class Index extends Container
{
    protected function _construct(): void
    {
        $this->_blockGroup = 'MageOS_WorkflowsApprovals';
        $this->_controller = 'adminhtml_approval';
        $this->_headerText = __('Approval Tasks');
        parent::_construct();
    }
}
