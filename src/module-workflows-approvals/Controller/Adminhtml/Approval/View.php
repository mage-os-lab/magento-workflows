<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use MageOS\WorkflowsApprovals\Api\ApprovalRepositoryInterface;

/**
 * Decision view (docs/discovery/approval-gate.md §6): title, instructions,
 * entity summary + link, the execution timeline so far, note field, and (when
 * declared) the generated payload_fields form. Viewing is gated
 * ::approvals_view; the Approve/Reject buttons rendered inside
 * Block\Adminhtml\Approval\DecisionPanel are separately hidden unless the
 * viewer also holds ::approvals_decide (checked there via AuthorizationInterface,
 * not by this controller's ACL — viewing and deciding are different grants, §5).
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_WorkflowsApprovals::approvals_view';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly ApprovalRepositoryInterface $approvalRepository,
        private readonly Registry $coreRegistry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $uuid = (string) $this->getRequest()->getParam('uuid');

        try {
            $approval = $uuid !== '' ? $this->approvalRepository->getByUuid($uuid) : null;
        } catch (NoSuchEntityException $e) {
            $approval = null;
        }

        if ($approval === null) {
            $this->messageManager->addErrorMessage(__('This approval task no longer exists.'));
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath('mageos_workflows_approvals/approval/index');
        }

        $this->coreRegistry->register('mageos_current_approval', $approval);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_WorkflowsApprovals::approval_index');
        $resultPage->getConfig()->getTitle()->prepend(__('Approval: %1', $approval->getTitle()));
        return $resultPage;
    }
}
