<?php
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
     */
    public function getByUuid(string $uuid): ApprovalInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): ApprovalSearchResultsInterface;
}
