<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\WorkflowExecution;
use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for the actions-core integration suites (docs/20 §5,
 * #18–21): builds a real ExecutionContext around a transient (unsaved)
 * WorkflowExecution so actions receive the same dedupe key / entity id /
 * store id they would at runtime. The execution carries a fixed UUID per
 * context so the UUID+step-key dedupe/guard markers are deterministic and a
 * redelivery can be simulated by executing twice with the SAME context.
 */
abstract class ActionTestCase extends TestCase
{
    /**
     * Build an execution context for an entity, with a controllable UUID and
     * current step (the dedupe key is uuid + current step).
     */
    protected function buildContext(
        int $entityId,
        int $storeId = 1,
        string $currentStep = 's1',
        string $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        array $trigger = [],
        array $steps = [],
        array $workflow = ['id' => 1, 'name' => 'Integration Workflow', 'version' => 1]
    ): ExecutionContextInterface {
        /** @var WorkflowExecution $execution */
        $execution = Bootstrap::getObjectManager()->create(WorkflowExecution::class);
        $execution->setUuid($uuid);
        $execution->setEntityId($entityId);
        $execution->setStoreId($storeId);
        $execution->setCurrentStep($currentStep);
        $execution->setWorkflowId((int)($workflow['id'] ?? 1));
        $execution->setWorkflowVersion((int)($workflow['version'] ?? 1));

        return new ExecutionContext($execution, $trigger, $steps, $workflow);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function resolve(string $class): object
    {
        return Bootstrap::getObjectManager()->get($class);
    }
}
