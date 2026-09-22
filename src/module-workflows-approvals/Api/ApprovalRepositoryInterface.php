<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterface;

/**
 * Read surface for approval tasks (§5). Tasks are addressed by uuid only; the
 * int PK never leaves the server.
 */
interface ApprovalRepositoryInterface
{
    /**
     * @throws NoSuchEntityException when no task carries the uuid
     * @param string $uuid
     * @return \MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface
     */
    public function getByUuid(string $uuid): ApprovalInterface;

    /**
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \MageOS\WorkflowsApprovals\Api\Data\ApprovalSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ApprovalSearchResultsInterface;
}
