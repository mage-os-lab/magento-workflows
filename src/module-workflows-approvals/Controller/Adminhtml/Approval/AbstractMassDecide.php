<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Controller\Adminhtml\Approval;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use MageOS\WorkflowsApprovals\Model\Exception\MassDecideCapExceededException;
use MageOS\WorkflowsApprovals\Model\MassDecideProcessor;
use MageOS\WorkflowsApprovals\Model\ResourceModel\Approval\CollectionFactory;

/**
 * Shared mass-decide loop (docs/discovery/approval-gate.md §6): thin — every
 * decision, per row, is Model\MassDecideProcessor's job (allow_bulk gate, the
 * cap, per-row claims/exceptions/counts). ADMIN_RESOURCE is ::approvals_decide,
 * not ::approvals_view — deciding in bulk is still deciding.
 */
abstract class AbstractMassDecide extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_WorkflowsApprovals::approvals_decide';

    private const CONFIG_CAP = 'mageos_workflows/guards/approval_mass_decide_cap';
    private const DEFAULT_CAP = 200;

    public function __construct(
        Action\Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly MassDecideProcessor $massDecideProcessor,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
    }

    abstract protected function getDecision(): string;

    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('mageos_workflows_approvals/approval/index');

        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $tasks = $collection->getItems();

        $note = $this->getRequest()->getParam('note');
        $note = is_string($note) && trim($note) !== '' ? $note : null;
        $adminUserId = (string) ($this->_auth->getUser()?->getId() ?? '');
        $cap = (int) $this->scopeConfig->getValue(self::CONFIG_CAP);
        if ($cap <= 0) {
            $cap = self::DEFAULT_CAP;
        }

        try {
            $result = $this->massDecideProcessor->process(
                $tasks,
                $this->getDecision(),
                $note,
                'admin',
                $adminUserId,
                $cap
            );
        } catch (MassDecideCapExceededException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect;
        }

        $this->messageManager->addSuccessMessage(__($result->toMessage()));
        return $resultRedirect;
    }
}
