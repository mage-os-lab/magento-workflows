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
     */
    public function save(WorkflowInterface $workflow): WorkflowInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $workflowId): WorkflowInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(WorkflowInterface $workflow): bool;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $workflowId): bool;
}
