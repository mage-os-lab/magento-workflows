<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

/**
 * Single entry point for all trigger types (event, schedule, manual).
 *
 * Creates the execution row (debounced, chain-depth-guarded) and publishes it
 * to the workflow.execute queue. Returns null when dispatch was suppressed
 * (workflow disabled, scope mismatch, debounce, loop guard, bulk suppression).
 */
interface DispatcherInterface
{
    /**
     * @param int $workflowId
     * @param array $triggerPayload Pre-hydrated trigger snapshot (async-events resolved service output)
     * @param string $triggerType One of WorkflowInterface::TRIGGER_TYPE_*
     * @param int $chainDepth Propagated chain depth for loop guarding
     */
    public function dispatch(
        int $workflowId,
        array $triggerPayload,
        string $triggerType = 'event',
        int $chainDepth = 0
    ): ?WorkflowExecutionInterface;
}
