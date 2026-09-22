<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Block\Adminhtml\Approval;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\CollectionFactory;
use MageOS\WorkflowsAdminUi\Model\OptionLabel;
use MageOS\WorkflowsAdminUi\Model\Source\EntityType;
use MageOS\WorkflowsAdminUi\Model\Source\ExecutionStatus;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\DueInFormatter;
use MageOS\WorkflowsApprovals\Model\EntityUrlResolver;
use MageOS\WorkflowsApprovals\Model\Source\ApprovalStatus;

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
        private readonly ApprovalStatus $approvalStatusSource,
        private readonly EntityType $entityTypeSource,
        private readonly ExecutionStatus $executionStatusSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * "Order #142" — the same entity-type label the approvals grid renders,
     * raw code when the type's pack is not installed.
     */
    public function getEntityLabel(): string
    {
        $approval = $this->getApproval();
        if ($approval === null) {
            return '';
        }
        $entityType = (string) $approval->getEntityType();

        return $entityType === ''
            ? (string) $approval->getEntityId()
            : sprintf(
                '%s #%d',
                OptionLabel::resolve($this->entityTypeSource, $entityType),
                $approval->getEntityId()
            );
    }

    public function getStatusLabel(): string
    {
        $approval = $this->getApproval();

        return $approval === null
            ? ''
            : OptionLabel::resolve($this->approvalStatusSource, (string) $approval->getStatus());
    }

    /**
     * Step statuses share the execution status code set (minus `cancelled`), so
     * the admin-ui ExecutionStatus source labels this timeline too.
     */
    public function getStepStatusLabel(?string $status): string
    {
        return OptionLabel::resolve($this->executionStatusSource, (string) $status);
    }

    /**
     * Assignee roles are free-form admin role names carried on the task, so no
     * option source can enumerate them: humanize the stored token in place
     * ("finance_manager" => "Finance Manager"). The raw value stays available in
     * the cell's title attribute.
     */
    public function getAssigneeRoleLabel(): string
    {
        $approval = $this->getApproval();
        $role = $approval === null ? '' : trim((string) $approval->getAssigneeRole());
        if ($role === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-', '.'], ' ', $role));
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
