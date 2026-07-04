<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

/**
 * Dry-run entry point for REST + admin (03): a synchronous, side-effect-free
 * preview of a definition against a specific entity (or a synthetic trigger
 * payload for CI, snapshot-only fidelity). Runs the validation subset and, on a
 * sound graph, walks it — no queue, no execution row, no real secrets resolved.
 *
 * Two shapes, deliberately non-colliding with GET /V1/workflows/:workflowId
 * (distinct method + segment depth): a posted definition, and a saved workflow
 * by id.
 *
 * @api
 */
interface WorkflowDryRunInterface
{
    /**
     * Dry-run a posted (possibly unsaved) definition.
     *
     * @param string $definition raw definition JSON
     * @param string $entityType workflow entity type (sales_order, customer, …)
     * @param string|null $conditionsSerialized root condition tree JSON
     * @param int|null $entityId subject entity id (omit when $triggerPayload is given)
     * @param mixed[]|null $triggerPayload synthetic payload (CI); bypasses hydration
     * @return \MageOS\Workflows\Api\Data\DryRunResultInterface
     */
    public function runOnDefinition(
        string $definition,
        string $entityType,
        ?string $conditionsSerialized = null,
        ?int $entityId = null,
        ?array $triggerPayload = null
    ): \MageOS\Workflows\Api\Data\DryRunResultInterface;

    /**
     * Dry-run a saved workflow by id.
     *
     * @param int $workflowId
     * @param int|null $entityId subject entity id (omit when $triggerPayload is given)
     * @param mixed[]|null $triggerPayload synthetic payload (CI); bypasses hydration
     * @return \MageOS\Workflows\Api\Data\DryRunResultInterface
     */
    public function runOnSaved(
        int $workflowId,
        ?int $entityId = null,
        ?array $triggerPayload = null
    ): \MageOS\Workflows\Api\Data\DryRunResultInterface;
}
