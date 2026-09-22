<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly WorkflowRepositoryInterface $workflowRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $workflowId = (int) $this->getRequest()->getParam('workflow_id');

        if ($workflowId) {
            try {
                $this->workflowRepository->deleteById($workflowId);
                $this->messageManager->addSuccessMessage(__('The workflow has been deleted.'));
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(__('The workflow could not be deleted.'));
                return $resultRedirect->setPath('mageos_workflows/workflow/edit', ['workflow_id' => $workflowId]);
            }
        }

        return $resultRedirect->setPath('mageos_workflows/workflow/index');
    }
}
