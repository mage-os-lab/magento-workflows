<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * Approvals grid (docs/discovery/approval-gate.md §6). Default filter
 * status=open: a bare navigation (no filters_modifier param at all) redirects
 * once to the same page carrying `filters_modifier[status]` — the same,
 * already-real Magento UI mechanism
 * MageOS\WorkflowsAdminExtension\ViewModel\GridStrip::getViewUrl uses to
 * deep-link a pre-filtered grid — so the default is a normal, changeable grid
 * filter rather than a locked one.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_WorkflowsApprovals::approvals_view';

    private const ROUTE = 'mageos_workflows_approvals/approval/index';

    public function __construct(
        Action\Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->getRequest()->getParam('filters_modifier') === null
            && $this->getRequest()->getParam('reset') === null
        ) {
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath(self::ROUTE, self::defaultFilterParams());
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('MageOS_WorkflowsApprovals::approval_index');
        $resultPage->getConfig()->getTitle()->prepend(__('Approval Tasks'));
        return $resultPage;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultFilterParams(): array
    {
        return [
            'filters_modifier' => [
                'status' => ['condition_type' => 'eq', 'value' => ApprovalInterface::STATUS_OPEN],
            ],
        ];
    }
}
