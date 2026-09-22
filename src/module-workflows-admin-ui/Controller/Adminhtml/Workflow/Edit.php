<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manage';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly Registry $coreRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $workflowId = (int) $this->getRequest()->getParam('workflow_id');

        if ($workflowId) {
            try {
                $workflow = $this->workflowRepository->getById($workflowId);
                $this->coreRegistry->register('mageos_current_workflow', $workflow);
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('This workflow no longer exists.'));
                /** @var Redirect $resultRedirect */
                $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                return $resultRedirect->setPath('mageos_workflows/workflow/index');
            }
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Workflows::workflow_index');
        $resultPage->getConfig()->getTitle()->prepend($workflowId ? __('Edit Workflow') : __('New Workflow'));
        return $resultPage;
    }
}
