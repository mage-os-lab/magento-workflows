<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

/**
 * Save-time static analysis of a serialized condition tree
 * (docs/06-conditions.md, phase-1 planning): every attribute the tree
 * references is classified against the trigger's declared snapshot shape.
 * If everything is in_snapshot AND no hydration-forcing node types appear,
 * evaluation is guaranteed zero-query.
 *
 * Node-type awareness (F4): some condition-node types force a runtime
 * lookup regardless of which attributes they reference — a childless
 * NOT-EXISTS related-entity node references zero attributes and would
 * otherwise classify zero-query. Those types are registered via the di.xml
 * `hydrationForcingNodeTypes` argument (first entry arrives with the
 * RelatedEntity combine, docs/discovery/implementation/02-*.md); any of
 * them present in the tree is reported under forcing_node_types.
 *
 * Pure and stateless — the snapshot shape (which attributes a trigger
 * payload carries) comes from the trigger's declared resolver/service class
 * and is passed in, keeping the class shim-testable. Used by the admin UI
 * (hydration-cost hinting) and by workflow save validation (batch profile,
 * 05).
 */
class AttributeClassifier
{
    /**
     * @param string[] $hydrationForcingNodeTypes condition-node "type" values
     *        that force hydration regardless of attributes (di.xml-registered)
     */
    public function __construct(
        private readonly array $hydrationForcingNodeTypes = []
    ) {
    }

    /**
     * @param array $conditionTree decoded salesrule-style condition tree
     *        (nodes: type/attribute/operator/value, children under "conditions")
     * @param string[] $snapshotAttributes attribute codes present in the
     *        trigger payload snapshot
     * @return array{in_snapshot: string[], needs_hydration: string[], forcing_node_types: string[]}
     */
    public function classify(array $conditionTree, array $snapshotAttributes): array
    {
        $attributes = [];
        $forcingTypes = [];
        $this->collect($conditionTree, $attributes, $forcingTypes);

        $result = [
            'in_snapshot' => [],
            'needs_hydration' => [],
            'forcing_node_types' => array_values(array_unique($forcingTypes)),
        ];
        foreach (array_values(array_unique($attributes)) as $attribute) {
            $bucket = in_array($attribute, $snapshotAttributes, true) ? 'in_snapshot' : 'needs_hydration';
            $result[$bucket][] = $attribute;
        }
        return $result;
    }

    /**
     * True only when evaluation is guaranteed zero-query: every referenced
     * attribute is in the snapshot and no node type forces hydration.
     */
    public function isZeroQuery(array $conditionTree, array $snapshotAttributes): bool
    {
        $result = $this->classify($conditionTree, $snapshotAttributes);
        return $result['needs_hydration'] === [] && $result['forcing_node_types'] === [];
    }

    /**
     * @param string[] $attributes
     * @param string[] $forcingTypes
     */
    private function collect(array $node, array &$attributes, array &$forcingTypes): void
    {
        $attribute = $node['attribute'] ?? null;
        if (is_string($attribute) && $attribute !== '') {
            $attributes[] = $attribute;
        }
        $type = $node['type'] ?? null;
        if (is_string($type) && in_array($type, $this->hydrationForcingNodeTypes, true)) {
            $forcingTypes[] = $type;
        }
        $children = $node['conditions'] ?? null;
        if (!is_array($children)) {
            return;
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collect($child, $attributes, $forcingTypes);
            }
        }
    }
}
