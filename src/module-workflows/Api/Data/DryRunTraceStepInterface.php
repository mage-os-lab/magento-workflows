<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One visited step of a dry-run trace, flattened for transport (03). Fan-out is
 * encoded by {@see getPathIds()} — a rejoined shared tail appears once carrying
 * every contributing path id. The arbitrarily-shaped nested fields (interpolated
 * config, condition detail, delay timing) travel as JSON strings so the contract
 * stays flat and forward-compatible.
 *
 * @api
 */
interface DryRunTraceStepInterface
{
    public function getStepKey(): string;

    public function getType(): string;

    /**
     * would_run | would_fail | skipped | production_stops_here
     */
    public function getStatus(): string;

    /**
     * @return string[] path ids that pass through this step
     */
    public function getPathIds(): array;

    /**
     * Plain-language summary of what the step would do; null when not applicable.
     */
    public function getWould(): ?string;

    /**
     * The single edge this step routes over, or null for a fan-out step (wait,
     * or an unevaluable branch/switch) whose routing is encoded by path ids.
     */
    public function getEdgeTaken(): ?string;

    /**
     * @return string[] annotations (both-paths note, redaction badge, …)
     */
    public function getNotes(): array;

    /**
     * Interpolated config JSON, secrets already redacted.
     */
    public function getConfig(): string;

    /**
     * Condition detail JSON ({serialized, result, revalidated}) or null.
     */
    public function getCondition(): ?string;

    /**
     * Delay/wait timing JSON ({resume_at, clamped, timezone}) or null.
     */
    public function getTiming(): ?string;
}
