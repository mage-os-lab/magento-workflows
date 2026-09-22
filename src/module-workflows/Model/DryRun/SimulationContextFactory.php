<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * Builds the transient, simulation-flagged {@see ExecutionContext} a dry-run
 * walks over. The execution row it wraps is never persisted — it only carries
 * the entity/store coordinates and the trigger snapshot the shared semantic
 * components read. Isolated behind this factory so the dry-run service stays
 * free of the generated data-model factory (and thus unit-testable).
 */
class SimulationContextFactory
{
    public function __construct(
        private readonly WorkflowExecutionInterfaceFactory $executionFactory
    ) {
    }

    /**
     * @param array $trigger pre-hydrated trigger payload snapshot
     */
    public function create(
        int $workflowId,
        string $entityType,
        int $entityId,
        int $storeId,
        array $trigger,
        string $workflowName
    ): ExecutionContext {
        /** @var WorkflowExecutionInterface $execution */
        $execution = $this->executionFactory->create();
        $execution->setUuid('dry-run');
        $execution->setWorkflowId($workflowId);
        $execution->setEntityId($entityId);
        $execution->setStoreId($storeId);
        $execution->setStatus(WorkflowExecutionInterface::STATUS_RUNNING);

        return new ExecutionContext(
            $execution,
            $trigger,
            [],
            ['id' => $workflowId, 'name' => $workflowName, 'entity_type' => $entityType],
            true
        );
    }
}
