<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Block\Adminhtml\Approval;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\CollectionFactory;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\DueInFormatter;
use MageOS\WorkflowsApprovals\Model\EntityUrlResolver;

/**
 * Decision view page (docs/discovery/approval-gate.md §6): task summary, entity
 * link, and the execution timeline so far. admin-ui does not expose a reusable
 * timeline block/viewmodel (its Execution\View block reads the step collection
 * directly), so this block does the same — reading the same public
 * WorkflowExecutionStep CollectionFactory, no admin-ui edit required. The
 * actual decision-taking UI (note/fields/buttons) is the separate, reusable
 * DecisionPanel block embedded via layout as this page's child.
 */
class View extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly CollectionFactory $stepCollectionFactory,
        private readonly EntityUrlResolver $entityUrlResolver,
        private readonly DueInFormatter $dueInFormatter,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getApproval(): ?ApprovalInterface
    {
        $approval = $this->coreRegistry->registry('mageos_current_approval');
        return $approval instanceof ApprovalInterface ? $approval : null;
    }

    public function getWorkflowName(): string
    {
        $approval = $this->getApproval();
        if ($approval === null) {
            return '';
        }
        try {
            return $this->workflowRepository->getById($approval->getWorkflowId())->getName();
        } catch (NoSuchEntityException $e) {
            return (string) __('Workflow #%1 (deleted)', $approval->getWorkflowId());
        }
    }

    public function getEntityUrl(): ?string
    {
        $approval = $this->getApproval();
        if ($approval === null) {
            return null;
        }
        return $this->entityUrlResolver->getUrl($approval->getEntityType(), $approval->getEntityId());
    }

    public function getDueInText(): string
    {
        $approval = $this->getApproval();
        return $approval !== null ? $this->dueInFormatter->format($approval->getDueAt()) : '—';
    }

    /**
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface[]
     */
    public function getSteps(): array
    {
        $approval = $this->getApproval();
        if ($approval === null) {
            return [];
        }
        $collection = $this->stepCollectionFactory->create();
        $collection->addFieldToFilter('execution_id', $approval->getExecutionId());
        $collection->setOrder('step_execution_id', 'ASC');
        return $collection->getItems();
    }
}
