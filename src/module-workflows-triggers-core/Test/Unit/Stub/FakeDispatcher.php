<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Stub;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\DispatcherInterface;

/**
 * Scripted workflow dispatcher: records dispatch()/resumeWaiting() calls and
 * returns (or throws) whatever the test configured.
 */
class FakeDispatcher implements DispatcherInterface
{
    /** @var array<int, array{workflowId: int, payload: array, triggerType: string, chainDepth: int}> */
    public array $dispatchCalls = [];

    /** @var array<int, array{workflowId: int, event: string, payload: array}> */
    public array $resumeCalls = [];

    public ?WorkflowExecutionInterface $dispatchResult = null;

    public ?\Throwable $dispatchThrows = null;

    public int $resumeResult = 0;

    public ?\Throwable $resumeThrows = null;

    public function dispatch(
        int $workflowId,
        array $triggerPayload,
        string $triggerType = 'event',
        int $chainDepth = 0
    ): ?WorkflowExecutionInterface {
        $this->dispatchCalls[] = [
            'workflowId' => $workflowId,
            'payload' => $triggerPayload,
            'triggerType' => $triggerType,
            'chainDepth' => $chainDepth,
        ];
        if ($this->dispatchThrows !== null) {
            throw $this->dispatchThrows;
        }
        return $this->dispatchResult;
    }

    public function resumeWaiting(int $workflowId, string $event, array $eventPayload): int
    {
        $this->resumeCalls[] = ['workflowId' => $workflowId, 'event' => $event, 'payload' => $eventPayload];
        if ($this->resumeThrows !== null) {
            throw $this->resumeThrows;
        }
        return $this->resumeResult;
    }
}
