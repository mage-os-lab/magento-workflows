<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowInterface;

interface WorkflowRepositoryInterface
{
    /**
     * Saving bumps the definition version and archives the prior revision.
     *
     * @throws CouldNotSaveException
     * @param \MageOS\Workflows\Api\Data\WorkflowInterface $workflow
     * @return \MageOS\Workflows\Api\Data\WorkflowInterface
     */
    public function save(WorkflowInterface $workflow): WorkflowInterface;

    /**
     * @throws NoSuchEntityException
     * @param int $workflowId
     * @return \MageOS\Workflows\Api\Data\WorkflowInterface
     */
    public function getById(int $workflowId): WorkflowInterface;

    /**
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Magento\Framework\Api\SearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * @throws CouldNotDeleteException
     * @param \MageOS\Workflows\Api\Data\WorkflowInterface $workflow
     * @return bool
     */
    public function delete(WorkflowInterface $workflow): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     * @param int $workflowId
     * @return bool
     */
    public function deleteById(int $workflowId): bool;
}
