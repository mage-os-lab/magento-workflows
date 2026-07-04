<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One step row of GET /V1/workflow-executions/:executionId/steps (07, canvas
 * execution overlay). A focused projection of WorkflowExecutionStepInterface —
 * exactly the fields the overlay tints the graph with. Duration is derived
 * client-side from started_at/finished_at (both MySQL datetime strings, or null
 * while pending/running).
 *
 * @api
 */
interface ExecutionStepStateInterface
{
    public function getStepKey(): string;

    /**
     * pending | running | waiting | complete | failed | skipped
     */
    public function getStatus(): string;

    public function getStartedAt(): ?string;

    public function getFinishedAt(): ?string;

    /**
     * Step result payload, JSON string as stored, or null.
     */
    public function getResult(): ?string;

    /**
     * Failure message, or null.
     */
    public function getError(): ?string;
}
