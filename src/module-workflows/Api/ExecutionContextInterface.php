<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

/**
 * The variable bag a workflow execution carries: trigger snapshot, step
 * outputs, workflow metadata. Serialized into mageos_workflow_execution.context
 * between steps; delays rely on this being the complete resumable state.
 *
 * This is the context handed to every ActionInterface::execute() /
 * SimulateableActionInterface::simulate() call.
 *
 * @api
 */
interface ExecutionContextInterface
{
    public function getExecution(): WorkflowExecutionInterface;

    public function getEntityId(): int;

    public function getStoreId(): int;

    /**
     * True in shadow-mode / dry-run executions: actions must not mutate anything
     */
    public function isSimulation(): bool;

    /**
     * Pre-hydrated trigger payload snapshot
     */
    public function getTrigger(): array;

    /**
     * step_key => action output
     */
    public function getSteps(): array;

    /**
     * ['id' => .., 'name' => .., 'version' => ..]
     */
    public function getWorkflow(): array;

    public function setStepOutput(string $stepKey, array $output): void;

    /**
     * Dot-path lookup across the bag: "trigger.grand_total", "steps.fraud.response.score"
     */
    public function resolvePath(string $path): mixed;

    /**
     * Per-step idempotency key for non-idempotent actions under at-least-once delivery
     */
    public function getDedupeKey(string $stepKey): string;

    /**
     * Portion of the bag persisted between steps (execution.context column)
     */
    public function toArray(): array;
}
