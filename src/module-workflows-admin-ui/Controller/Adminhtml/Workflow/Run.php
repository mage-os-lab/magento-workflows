<?php
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Controller\Adminhtml\Workflow;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;

/**
 * Manual "Run Now" dispatch from the workflow edit page.
 *
 * POST ONLY. This action fires REAL side effects against a caller-chosen entity
 * id — refunds, customer emails, outbound webhooks — so it is a state change and
 * has to be requested like one. It used to be a GET, protected by nothing but
 * the adminhtml secret URL key, which merchants routinely switch off
 * (Stores > Configuration > Advanced > Admin > Security > "Add Secret Key to
 * URLs" = No) and which leaks through Referer headers and browser history
 * anyway. A logged-in admin could then be made to refund an order by loading an
 * image tag. Every other mutating controller in this package is already POST;
 * this one is now consistent with them.
 *
 * Declaring HttpPostActionInterface is what makes the framework reject the GET,
 * and POST additionally routes dispatch through the CSRF gate:
 * Magento\Backend\App\AbstractAction::_processUrlKeys() validates the form key
 * on every POST from a logged-in admin (and skips the secret-key check in that
 * branch — the form key is the stronger token). This controller does NOT
 * implement CsrfAwareActionInterface, so nothing opts back out of that.
 *
 * The caller is the Run Now modal (RunNowModal + run-now-modal.js), which posts
 * a virtual form carrying form_key and entity_id. Dispatch itself remains
 * idempotent-guarded by the engine's debounce window, and the dedicated
 * manual-run ACL resource — not just "manage" — is still required.
 */
class Run extends Action implements HttpPostActionInterface
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
