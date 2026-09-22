<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One step row of GET /V1/workflow-executions/:executionId/steps (07, canvas
 * execution overlay). A deliberately NARROW, non-sensitive projection.
 *
 * SECURITY: production step `result` blobs are written by the live Executor
 * AFTER config interpolation, so they can embed real secret values (a webhook
 * URL with a token, an SMTP error echoing credentials). This DTO therefore does
 * NOT expose the raw result. It surfaces only what the overlay needs to tint the
 * graph:
 *
 *   - status + timing (started_at/finished_at; duration derived client-side);
 *   - edge_taken: the routing outcome, derived from a WHITELIST of safe result
 *     keys only (branch `result` bool, switch `matched` case key, wait
 *     `resolution`) — an action step's arbitrary `output` is never read;
 *   - error_summary: the first line of any error, truncated and passed through
 *     the secret-redaction filter — never the raw error blob.
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
     * The edge this step routed over, using Definition::getStepEdges naming
     * (`on_true`/`on_false`, `case:<key>`/`default`, `on_event`/`on_timeout`),
     * or null for a linear/unrouted step. Derived from safe result keys only.
     */
    public function getEdgeTaken(): ?string;

    /**
     * Redacted, truncated first line of the step error, or null when the step
     * did not fail. Never the raw error blob.
     */
    public function getErrorSummary(): ?string;
}
