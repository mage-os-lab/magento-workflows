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
     * @param \MageOS\Workflows\Api\Data\WorkflowExecutionInterface $execution
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionInterface
     */
    public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface;

    /**
     * @throws NoSuchEntityException
     * @param int $executionId
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionInterface
     */
    public function getById(int $executionId): WorkflowExecutionInterface;

    /**
     * @throws NoSuchEntityException
     * @param string $uuid
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionInterface
     */
    public function getByUuid(string $uuid): WorkflowExecutionInterface;

    /**
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Framework\Api\SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;
}
