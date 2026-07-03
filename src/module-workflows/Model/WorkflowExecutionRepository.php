<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecution as WorkflowExecutionResource;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecution\CollectionFactory;

class WorkflowExecutionRepository implements WorkflowExecutionRepositoryInterface
{
    public function __construct(
        private readonly WorkflowExecutionFactory $executionFactory,
        private readonly WorkflowExecutionResource $executionResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(WorkflowExecutionInterface $execution): WorkflowExecutionInterface
    {
        /** @var WorkflowExecution $execution */
        try {
            $this->executionResource->save($execution);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save workflow execution: %1', $e->getMessage()), $e);
        }

        return $execution;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $executionId): WorkflowExecutionInterface
    {
        $execution = $this->executionFactory->create();
        $this->executionResource->load($execution, $executionId);
        if (!$execution->getExecutionId()) {
            throw new NoSuchEntityException(
                __('Workflow execution with id "%1" does not exist.', $executionId)
            );
        }

        return $execution;
    }

    /**
     * @inheritDoc
     */
    public function getByUuid(string $uuid): WorkflowExecutionInterface
    {
        $execution = $this->executionFactory->create();
        $this->executionResource->load($execution, $uuid, WorkflowExecutionInterface::UUID);
        if (!$execution->getExecutionId()) {
            throw new NoSuchEntityException(
                __('Workflow execution with uuid "%1" does not exist.', $uuid)
            );
        }

        return $execution;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): SearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }
}
