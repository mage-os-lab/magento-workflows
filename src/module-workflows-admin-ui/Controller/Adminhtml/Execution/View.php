<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Execution;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::view';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly Registry $coreRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $executionId = (int) $this->getRequest()->getParam('execution_id');

        try {
            $execution = $this->executionRepository->getById($executionId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This execution no longer exists.'));
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath('mageos_workflows/execution/index');
        }

        $this->coreRegistry->register('mageos_current_execution', $execution);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_Workflows::execution_index');
        $resultPage->getConfig()->getTitle()->prepend(__('Execution #%1', $execution->getExecutionId()));
        return $resultPage;
    }
}
