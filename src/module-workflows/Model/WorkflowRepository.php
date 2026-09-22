<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Api\SearchResultsInterfaceFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\ResourceModel\Workflow as WorkflowResource;
use MageOS\Workflows\Model\ResourceModel\Workflow\CollectionFactory;
use MageOS\Workflows\Model\ResourceModel\WorkflowRevision as WorkflowRevisionResource;

class WorkflowRepository implements WorkflowRepositoryInterface
{
    public function __construct(
        private readonly WorkflowFactory $workflowFactory,
        private readonly WorkflowResource $workflowResource,
        private readonly WorkflowRevisionResource $revisionResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SearchResultsInterfaceFactory $searchResultsFactory,
        private readonly WorkflowIndex $workflowIndex
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(WorkflowInterface $workflow): WorkflowInterface
    {
        /** @var Workflow $workflow */
        $connection = $this->workflowResource->getConnection();
        $connection->beginTransaction();
        try {
            $workflowId = $workflow->getWorkflowId();
            if ($workflowId) {
                $prior = $this->workflowFactory->create();
                $this->workflowResource->load($prior, $workflowId);
                if ($prior->getWorkflowId()) {
                    if ($this->isDefinitionChanged($prior, $workflow)) {
                        $this->revisionResource->archive(
                            $workflowId,
                            $prior->getVersion(),
                            $prior->getDefinition(),
                            $prior->getConditionsSerialized()
                        );
                        $workflow->setVersion($prior->getVersion() + 1);
                    } else {
                        $workflow->setVersion($prior->getVersion());
                    }
                }
            }
            $this->workflowResource->save($workflow);
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw new CouldNotSaveException(__('Could not save workflow: %1', $e->getMessage()), $e);
        }
        $this->workflowIndex->clean();

        return $workflow;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $workflowId): WorkflowInterface
    {
        $workflow = $this->workflowFactory->create();
        $this->workflowResource->load($workflow, $workflowId);
        if (!$workflow->getWorkflowId()) {
            throw new NoSuchEntityException(
                __('Workflow with id "%1" does not exist.', $workflowId)
            );
        }

        return $workflow;
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

    /**
     * @inheritDoc
     */
    public function delete(WorkflowInterface $workflow): bool
    {
        /** @var Workflow $workflow */
        try {
            $this->workflowResource->delete($workflow);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete workflow: %1', $e->getMessage()), $e);
        }
        $this->workflowIndex->clean();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById(int $workflowId): bool
    {
        return $this->delete($this->getById($workflowId));
    }

    /**
     * Version bump gate: only definition or conditions changes create a revision
     */
    private function isDefinitionChanged(WorkflowInterface $prior, WorkflowInterface $workflow): bool
    {
        return $prior->getDefinition() !== $workflow->getDefinition()
            || ($prior->getConditionsSerialized() ?? '') !== ($workflow->getConditionsSerialized() ?? '');
    }
}
