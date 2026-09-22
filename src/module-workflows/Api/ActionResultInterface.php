<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * The outcome of a workflow action's execute()/simulate() call.
 *
 * Semantics consumed by the engine:
 *  - success: output is merged into the execution context as steps.<step_key>
 *  - skipped: recorded, execution continues (output may carry a "reason")
 *  - failure + retryable: the step is parked and the queue message redelivered
 *  - failure + not retryable: terminal step failure, the execution fails
 *
 * Use the MageOS\Workflows\Model\Action\ActionResult factories
 * (success/skipped/failure) to build instances.
 *
 * @api
 */
interface ActionResultInterface
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILURE = 'failure';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * One of the STATUS_* constants
     */
    public function getStatus(): string;

    public function isSuccess(): bool;

    public function isFailure(): bool;

    /**
     * Merged into the execution context as steps.<step_key>
     */
    public function getOutput(): array;

    /**
     * Failure only: true = redeliver via queue, false = terminal step failure
     */
    public function isRetryable(): bool;

    /**
     * Human-readable error for failures; null otherwise
     */
    public function getError(): ?string;
}
