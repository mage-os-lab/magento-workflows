<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use MageOS\WorkflowsApprovals\Model\ApprovalService;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;

/**
 * Decide POST target (docs/discovery/approval-gate.md §6), gated
 * ::approvals_decide and form-key validated (standard Backend\App\Action POST
 * behaviour — no extra code needed here). Calls the single decision path
 * (ApprovalService::decide) with actorType 'admin' and the session admin user
 * id, so races/validation/audit are identical to REST and the mass action.
 * Posted from either the standalone decision view or the execution-view panel
 * (Block\Adminhtml\Approval\DecisionPanel, both forms) — the latter carries a
 * hidden execution_id so a successful decision returns to the execution page
 * the merchant was already on rather than the task's own view.
 */
class Decide extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_WorkflowsApprovals::approvals_decide';

    public function __construct(
        Action\Context $context,
        private readonly ApprovalService $approvalService
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $uuid = (string) $this->getRequest()->getParam('uuid');
        $decision = (string) $this->getRequest()->getParam('decision');
        $note = $this->getRequest()->getParam('note');
        $note = is_string($note) && trim($note) !== '' ? $note : null;
        $payload = $this->getRequest()->getParam('payload', []);
        $payload = is_array($payload) ? $payload : [];

        $adminUserId = (string) ($this->_auth->getUser()?->getId() ?? '');

        try {
            $this->approvalService->decide($uuid, $decision, $note, $payload, 'admin', $adminUserId);
            $this->messageManager->addSuccessMessage(__('The decision has been recorded.'));
        } catch (ApprovalDecisionException $e) {
            if (in_array($e->getApprovalCode(), [
                ApprovalDecisionException::CODE_ALREADY_DECIDED,
                ApprovalDecisionException::CODE_EXECUTION_GONE,
            ], true)) {
                $this->messageManager->addWarningMessage($e->getMessage());
                return $this->redirectToGrid();
            }
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->redirectBack($uuid);
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Something went wrong while recording the decision.')
            );
            return $this->redirectBack($uuid);
        }

        return $this->redirectBack($uuid);
    }

    private function redirectBack(string $uuid): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $executionId = $this->getRequest()->getParam('execution_id');
        if ($executionId !== null && ctype_digit((string) $executionId)) {
            return $resultRedirect->setPath('mageos_workflows/execution/view', ['execution_id' => $executionId]);
        }
        return $resultRedirect->setPath('mageos_workflows_approvals/approval/view', ['uuid' => $uuid]);
    }

    private function redirectToGrid(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('mageos_workflows_approvals/approval/index');
    }
}
