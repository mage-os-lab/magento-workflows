<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

interface WorkflowExecutionRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $executionId): WorkflowExecutionInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getByUuid(string $uuid): WorkflowExecutionInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
