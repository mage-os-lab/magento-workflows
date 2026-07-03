<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Forwards to Edit, which renders a "New Workflow" form when no workflow_id is present.
 */
class NewAction extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function execute(): ResultInterface
    {
        return $this->_forward('edit');
    }
}
