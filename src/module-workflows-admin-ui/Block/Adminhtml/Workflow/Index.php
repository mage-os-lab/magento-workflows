<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Block\Adminhtml\Workflow;

use Magento\Backend\Block\Widget\Container;

/**
 * Page container for the workflow grid: header text + the New Workflow
 * button. Deliberately NOT Widget\Grid\Container — that base class
 * synthesizes a legacy "{blockGroup}\Block\{controller}\Grid" child by
 * convention, and this page renders the mageos_workflows_listing
 * uiComponent instead; the phantom class made the whole page throw
 * "Invalid block type" on a real install (caught by the integration
 * lane's controller suite).
 */
class Index extends Container
{
    protected function _construct(): void
    {
        $this->_headerText = __('Workflows');
        parent::_construct();
        $this->buttonList->add(
            'add',
            [
                'label' => __('New Workflow'),
                'onclick' => sprintf("setLocation('%s')", $this->getUrl('*/*/new')),
                'class' => 'add primary',
            ]
        );
    }
}
