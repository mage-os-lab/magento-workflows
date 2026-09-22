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
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;

/**
 * Manual "Run Now" dispatch from the workflow edit page.
 *
 * GET with the standard adminhtml secret URL key (appended by the URL builder that renders
 * the button); dispatch itself is idempotent-guarded by the engine's debounce window.
 * Requires the dedicated manual-run ACL resource, not just "manage".
 */
class Run extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Workflows::manual_run';

    public function __construct(
        Action\Context $context,
        private readonly DispatcherInterface $dispatcher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $workflowId = (int) $this->getRequest()->getParam('workflow_id');
        if (!$workflowId) {
            $this->messageManager->addErrorMessage(__('A workflow ID is required to run a workflow.'));
            return $resultRedirect->setPath('mageos_workflows/workflow/index');
        }
        $resultRedirect->setPath('mageos_workflows/workflow/edit', ['workflow_id' => $workflowId]);

        $entityIdParam = $this->getRequest()->getParam('entity_id');
        $entityIdParam = is_scalar($entityIdParam) ? trim((string) $entityIdParam) : '';
        if ($entityIdParam === '' || !ctype_digit($entityIdParam)) {
            $this->messageManager->addErrorMessage(
                __('A numeric entity ID is required to run a workflow manually.')
            );
            return $resultRedirect;
        }

        try {
            $execution = $this->dispatcher->dispatch(
                $workflowId,
                ['entity_id' => (int) $entityIdParam],
                WorkflowInterface::TRIGGER_TYPE_MANUAL
            );
            if ($execution !== null) {
                $this->messageManager->addSuccessMessage(
                    __('The workflow run has been dispatched (execution %1).', $execution->getUuid())
                );
            } else {
                $this->messageManager->addWarningMessage(
                    __('Dispatch was suppressed (workflow disabled, out of scope, or debounced)')
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Something went wrong while dispatching the workflow.'));
        }

        return $resultRedirect;
    }
}
