<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

/**
 * Process-local marker for "an execution is running right now, at depth N".
 *
 * This is the missing half of the chain-depth loop guard
 * (docs/07-actions.md §Guards): executions have always CARRIED chain_depth
 * and Dispatcher has always ENFORCED it, but nothing ever fed a non-zero
 * depth into a dispatch — action-caused events dispatched fresh executions
 * at depth 0 forever, leaving the debounce window as the only cycle
 * breaker (which a slow loop through delay steps evades).
 *
 * The Executor brackets its walk with enter()/restore(); EventPublisher
 * stamps dispatchDepth() onto any payload it publishes while active
 * (ChainDepthContext::PAYLOAD_KEY, a reserved ride-along key); the
 * WorkflowNotifier strips the key and hands the depth to
 * Dispatcher::dispatch(), where the existing loop_guard_depth comparison
 * finally has something to compare against.
 *
 * Coverage note: only events flowing through this suite's EventPublisher
 * carry depth. Upstream async-events published by Magento/mageos observers
 * through the queue publisher are delivered in another process and dispatch
 * at depth 0 — those chains remain guarded by the debounce window and the
 * circuit breaker.
 *
 * Shared DI instance; per-process state. Queue consumers execute one
 * message at a time, so enter() never truly nests — the save/restore
 * contract is there so a hypothetical nested walk still unwinds correctly.
 */
class ChainDepthContext
{
    /**
     * Reserved payload key. Stripped by WorkflowNotifier before the payload
     * becomes a trigger snapshot; never visible to conditions or variables.
     */
    public const PAYLOAD_KEY = '__workflow_chain_depth';

    private ?int $dispatchDepth = null;

    /**
     * Marks an execution at $currentExecutionDepth as running; events it
     * causes should dispatch at depth + 1. Returns the previous marker for
     * restore().
     */
    public function enter(int $currentExecutionDepth): ?int
    {
        $previous = $this->dispatchDepth;
        $this->dispatchDepth = $currentExecutionDepth + 1;
        return $previous;
    }

    public function restore(?int $previous): void
    {
        $this->dispatchDepth = $previous;
    }

    public function isActive(): bool
    {
        return $this->dispatchDepth !== null;
    }

    /**
     * Depth a dispatch caused right now should carry. 0 outside any
     * execution (ordinary storefront/admin/cron activity).
     */
    public function dispatchDepth(): int
    {
        return $this->dispatchDepth ?? 0;
    }
}
