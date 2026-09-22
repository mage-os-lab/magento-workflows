<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Model\DryRun\SimulationContextFactory;
use MageOS\Workflows\Model\Execution\ExecutionContext;

/**
 * Builds a simulation ExecutionContext from the in-memory execution stub, so
 * DryRunService can be exercised without the generated data-model factory.
 */
class StubSimulationContextFactory extends SimulationContextFactory
{
    public function __construct()
    {
    }

    public function create(
        int $workflowId,
        string $entityType,
        int $entityId,
        int $storeId,
        array $trigger,
        string $workflowName
    ): ExecutionContext {
        return new ExecutionContext(
            new WorkflowExecutionStub('dry-run', $entityId, $storeId),
            $trigger,
            [],
            ['id' => $workflowId, 'name' => $workflowName, 'entity_type' => $entityType],
            true
        );
    }
}
