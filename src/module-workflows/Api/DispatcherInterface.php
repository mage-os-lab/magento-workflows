<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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

    /**
     * Wake executions of this workflow parked by a wait step on this event
     * for the event's entity. The matched executions resume through their
     * wait step's on_event edge; unmatched waits keep sleeping until the
     * timeout sweeper fires on_timeout.
     *
     * @param int $workflowId
     * @param string $event Trigger event name that just fired
     * @param array $eventPayload Pre-hydrated event payload (entity id source, exposed to the wait step's output)
     * @return int Number of executions resumed
     */
    public function resumeWaiting(int $workflowId, string $event, array $eventPayload): int;
}
