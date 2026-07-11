<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

/**
 * A contributor of computed aggregate attributes to a condition root (E2 /
 * domain-packs). An aggregate attribute is one that never appears in a trigger
 * snapshot and is materialized only at hydration time (e.g. a customer's
 * order-history totals), so conditions referencing it always classify as
 * needs_hydration and resolve in phase 2.
 *
 * Providers are registered per target entity type in AggregateProviderPool
 * (di.xml). Splitting metadata from computation lets a pack owning the queried
 * data (e.g. sales owning order history) contribute aggregates onto another
 * pack's root (the customer root) without either pack referencing the other.
 *
 * @api
 */
interface AggregateProviderInterface
{
    /**
     * Attribute metadata for the condition UI/classifier: the code offered as a
     * condition target => its label and workflow input type. The input type is
     * one of the condition-class vocabulary ('numeric', 'date', 'string', ...);
     * it drives getInputType()/getValueElementType() on the target root.
     *
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array;

    /**
     * Compute the aggregate values for one entity, merged into the hydrated
     * entity data. Absent keys (not null-set) fail toward false: an aggregate
     * with no meaningful value for this entity is omitted so it only matches
     * the negative operators (AbstractWorkflowCondition::validateAttribute()).
     *
     * @return array<string, mixed> aggregate code => value
     */
    public function getAggregates(int $entityId): array;
}
