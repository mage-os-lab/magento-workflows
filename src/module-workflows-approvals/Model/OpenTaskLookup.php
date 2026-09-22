<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use MageOS\WorkflowsApprovals\Api\ApprovalRepositoryInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * Finds the open approval task for one execution's currently-parked step — the
 * execution-view panel's render gate (docs/discovery/approval-gate.md §6): the
 * panel renders only when the parked step is an approval gate with a genuinely
 * open task, never for a plain wait/delay or an already-decided/expired one.
 */
class OpenTaskLookup
{
    public function __construct(
        private readonly ApprovalRepositoryInterface $approvalRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function findOpenTask(int $executionId, string $stepKey): ?ApprovalInterface
    {
        $this->searchCriteriaBuilder->addFilter(ApprovalInterface::EXECUTION_ID, $executionId);
        $this->searchCriteriaBuilder->addFilter(ApprovalInterface::STEP_KEY, $stepKey);
        $this->searchCriteriaBuilder->addFilter(ApprovalInterface::STATUS, ApprovalInterface::STATUS_OPEN);
        $criteria = $this->searchCriteriaBuilder->create();

        $results = $this->approvalRepository->getList($criteria)->getItems();
        return $results[0] ?? null;
    }
}
