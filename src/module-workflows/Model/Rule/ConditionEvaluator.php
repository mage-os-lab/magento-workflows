<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Relation\RelationContext;

/**
 * Orchestrates two-phase condition evaluation (docs/06-conditions.md).
 *
 * The condition tree (salesrule-style serialized JSON) is loaded into a
 * WorkflowRule whose root combine is resolved per entity type. The validated
 * model is a DataObject that carries, besides the entity data, the hydration
 * convention keys defined on HydrationProviderInterface:
 *
 *   __hydration_provider  HydrationProviderInterface instance
 *   __entity_type         execution entity type (e.g. sales_order)
 *   __entity_id           execution entity id ($ctx->getEntityId())
 *   __hydration_fresh     bool — bypass identity map on hydration
 *
 * Every condition/combine in the tree reaches phase-2 hydration through
 * these keys; cross-entity combines propagate them with adjusted
 * coordinates. This convention is the single wiring point between the
 * evaluator and the condition classes — keep them in sync.
 *
 * Phase 1 (evaluate / revalidate=false): the model wraps the frozen trigger
 * snapshot; hydration only happens lazily on attribute miss.
 * Post-delay revalidation (evaluateSerialized with $revalidateEntity=true):
 * the entity is re-hydrated FRESH and validated instead of the snapshot; a
 * vanished entity fails closed (false).
 */
class ConditionEvaluator
{
    /**
     * The allowlist is a REQUIRED dependency on purpose: it gates which
     * classes stored tree data may instantiate, and an optional-with-default
     * parameter would silently arrive null in production (the ObjectManager
     * does not auto-resolve defaulted parameters).
     */
    public function __construct(
        private readonly WorkflowRuleFactory $workflowRuleFactory,
        private readonly HydrationProviderInterface $hydrationProvider,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly RelationContext $relationContext,
        private readonly ConditionTypeAllowlist $typeAllowlist
    ) {
    }

    /**
     * Evaluate the workflow's root condition tree against the execution.
     * Empty/null conditions mean "always run" => true.
     */
    public function evaluate(WorkflowInterface $workflow, ExecutionContext $ctx): bool
    {
        return $this->doEvaluate(
            (string)($workflow->getConditionsSerialized() ?? ''),
            $workflow->getEntityType(),
            $ctx,
            false
        );
    }

    /**
     * Evaluate an arbitrary serialized condition tree (branch steps,
     * post-delay gates). $revalidateEntity=true re-hydrates the entity fresh
     * and validates against present-time state instead of the snapshot.
     */
    public function evaluateSerialized(
        string $conditionsSerialized,
        string $entityType,
        ExecutionContext $ctx,
        bool $revalidateEntity
    ): bool {
        return $this->doEvaluate($conditionsSerialized, $entityType, $ctx, $revalidateEntity);
    }

    private function doEvaluate(string $serialized, string $entityType, ExecutionContext $ctx, bool $fresh): bool
    {
        $tree = $this->decode($serialized);
        if ($tree === []) {
            return true;
        }
        // Refuse to load a tree whose `type` would instantiate a class
        // outside the registered condition surface — loadArray() hands the
        // raw string to the condition factory, and a hostile type's
        // constructor fires before anything can object. Same explicit-failure
        // contract as decode(): a tree that names an unknown class is
        // malformed data, not a skippable node.
        $this->assertTypesAllowed($tree);

        // Fresh per-tree relation resolution: clears any memo carried over from
        // a prior evaluation in the same (long-lived consumer) process. The
        // RelatedEntity combines thread the fresh flag onto the context
        // themselves from the model, so within this one tree a relation
        // referenced by several conditions still costs a single lookup.
        $this->relationContext->reset();

        $rule = $this->workflowRuleFactory->create();
        $rule->setEntityType($entityType);
        $rule->getConditions()->loadArray($tree);

        $model = $this->buildModel($entityType, $ctx, $fresh);
        if ($model === null) {
            // revalidate_entity=true and the entity no longer exists: fail closed
            return false;
        }

        return (bool)$rule->getConditions()->validate($model);
    }

    private function buildModel(string $entityType, ExecutionContext $ctx, bool $fresh): ?DataObject
    {
        if ($fresh) {
            $model = $this->hydrationProvider->getEntity($entityType, $ctx->getEntityId(), true);
            if ($model === null) {
                return null;
            }
        } else {
            $model = $this->dataObjectFactory->create(['data' => $ctx->getTrigger()]);
        }
        $model->setData(HydrationProviderInterface::KEY_PROVIDER, $this->hydrationProvider);
        $model->setData(HydrationProviderInterface::KEY_ENTITY_TYPE, $entityType);
        $model->setData(HydrationProviderInterface::KEY_ENTITY_ID, $ctx->getEntityId());
        $model->setData(HydrationProviderInterface::KEY_FRESH, $fresh);
        return $model;
    }

    /**
     * Recursive walk over the decoded tree: every node carrying a string
     * `type` must pass the allowlist (registered condition class, or an inert
     * non-class marker). Children live under `conditions` at every level.
     *
     * @throws \InvalidArgumentException when a node's type names an existing
     *         class outside the registered condition surface
     */
    private function assertTypesAllowed(array $node): void
    {
        $type = $node['type'] ?? null;
        if (is_string($type) && $type !== '' && !$this->typeAllowlist->isAllowed($type)) {
            throw new \InvalidArgumentException(sprintf(
                'Workflow condition type "%s" is not a registered condition class',
                $type
            ));
        }
        foreach ((array) ($node['conditions'] ?? []) as $child) {
            if (is_array($child)) {
                $this->assertTypesAllowed($child);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException when the serialized tree is malformed
     *         (fail explicitly rather than silently running/skipping actions)
     */
    private function decode(string $serialized): array
    {
        if (trim($serialized) === '') {
            return [];
        }
        try {
            $tree = json_decode($serialized, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                'Workflow conditions are not valid JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }
        if ($tree === null || $tree === []) {
            return [];
        }
        if (!is_array($tree)) {
            throw new \InvalidArgumentException('Workflow conditions must decode to a condition-tree array');
        }
        return $tree;
    }
}
