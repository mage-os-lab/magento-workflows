<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Snapshot-only membership test: does a single flat projection satisfy the
 * aggregated workflow's root conditions?
 *
 * The one evaluator shared by B1 (collected mode — one execution, no
 * per-execution re-filter) and B2 (accumulator — evaluated per event in the
 * dispatch hot path). Evaluation is zero-query by construction: the save-time
 * ProfileCheck guarantees the root conditions classify fully in-snapshot, so
 * no attribute miss ever reaches hydration.
 */
interface MembershipEvaluatorInterface
{
    /**
     * @param array<string, mixed> $flatItem a flat entity snapshot/projection
     */
    public function matches(WorkflowInterface $workflow, array $flatItem): bool;
}
