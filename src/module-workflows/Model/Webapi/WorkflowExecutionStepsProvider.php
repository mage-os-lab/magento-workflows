<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowExecutionStepsProviderInterface;
use MageOS\Workflows\Model\ResourceModel\WorkflowExecutionStep\CollectionFactory;

/**
 * GET /V1/workflow-executions/:executionId/steps (07): reads the step-execution
 * rows for one execution and projects them to ExecutionStepState DTOs. The
 * execution is loaded first so a missing id 404s (NoSuchEntityException from the
 * repository) rather than returning an empty list.
 */
class WorkflowExecutionStepsProvider implements WorkflowExecutionStepsProviderInterface
{
    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly CollectionFactory $stepCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSteps(int $executionId): array
    {
        $this->requireExecution($executionId);

        $steps = [];
        foreach ($this->loadStepModels($executionId) as $step) {
            $steps[] = new ExecutionStepState(
                $step->getStepKey(),
                $step->getStatus(),
                $step->getStartedAt(),
                $step->getFinishedAt(),
                $step->getResult(),
                $step->getError()
            );
        }
        return $steps;
    }

    /**
     * Existence check: NoSuchEntityException -> 404 for an unknown execution.
     * (Test seam.)
     */
    protected function requireExecution(int $executionId): void
    {
        $this->executionRepository->getById($executionId);
    }

    /**
     * The step-execution rows for one execution, in execution order.
     * (Test seam.)
     *
     * @return \MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface[]
     */
    protected function loadStepModels(int $executionId): array
    {
        $collection = $this->stepCollectionFactory->create();
        $collection->addFieldToFilter('execution_id', $executionId);
        $collection->setOrder('step_execution_id', 'ASC');

        return $collection->getItems();
    }
}
