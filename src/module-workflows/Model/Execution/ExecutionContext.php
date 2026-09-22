<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Execution;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;

/**
 * The variable bag an execution carries: trigger snapshot, step outputs,
 * workflow metadata. Serialized into mageos_workflow_execution.context
 * between steps; delays rely on this being the complete resumable state.
 */
class ExecutionContext implements ExecutionContextInterface
{
    /**
     * @param array $trigger Pre-hydrated trigger payload snapshot
     * @param array $steps step_key => action output
     * @param array $workflow ['id' => .., 'name' => .., 'version' => ..]
     */
    public function __construct(
        private readonly WorkflowExecutionInterface $execution,
        private array $trigger = [],
        private array $steps = [],
        private array $workflow = [],
        private readonly bool $simulation = false
    ) {
    }

    public function getExecution(): WorkflowExecutionInterface
    {
        return $this->execution;
    }

    public function getEntityId(): int
    {
        return $this->execution->getEntityId();
    }

    public function getStoreId(): int
    {
        return $this->execution->getStoreId();
    }

    /**
     * True in shadow-mode / dry-run executions: actions must not mutate anything
     */
    public function isSimulation(): bool
    {
        return $this->simulation;
    }

    public function getTrigger(): array
    {
        return $this->trigger;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getWorkflow(): array
    {
        return $this->workflow;
    }

    public function setStepOutput(string $stepKey, array $output): void
    {
        $this->steps[$stepKey] = $output;
    }

    /**
     * Dot-path lookup across the bag: "trigger.grand_total", "steps.fraud.response.score"
     */
    public function resolvePath(string $path): mixed
    {
        $segments = explode('.', $path);
        $root = array_shift($segments);
        $value = match ($root) {
            'trigger' => $this->trigger,
            'steps' => $this->steps,
            'workflow' => $this->workflow,
            default => null,
        };
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * Per-step idempotency key for non-idempotent actions under at-least-once delivery
     */
    public function getDedupeKey(string $stepKey): string
    {
        return $this->execution->getUuid() . ':' . $stepKey;
    }

    /**
     * Portion of the bag persisted between steps (execution.context column)
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->trigger,
            'steps' => $this->steps,
            'workflow' => $this->workflow,
        ];
    }
}
