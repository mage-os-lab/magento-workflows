<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

/**
 * Save-time static analysis of a serialized condition tree
 * (docs/06-conditions.md, phase-1 planning): every attribute the tree
 * references is classified against the trigger's declared snapshot shape.
 * If everything is in_snapshot, evaluation is guaranteed zero-query.
 *
 * Pure and stateless — used by the admin UI (hydration-cost hinting) and by
 * workflow save validation.
 */
class AttributeClassifier
{
    /**
     * @param array $conditionTree decoded salesrule-style condition tree
     *        (nodes: type/attribute/operator/value, children under "conditions")
     * @param string[] $snapshotAttributes attribute codes present in the
     *        trigger payload snapshot
     * @return array{in_snapshot: string[], needs_hydration: string[]}
     */
    public function classify(array $conditionTree, array $snapshotAttributes): array
    {
        $attributes = [];
        $this->collectAttributes($conditionTree, $attributes);

        $result = ['in_snapshot' => [], 'needs_hydration' => []];
        foreach (array_values(array_unique($attributes)) as $attribute) {
            $bucket = in_array($attribute, $snapshotAttributes, true) ? 'in_snapshot' : 'needs_hydration';
            $result[$bucket][] = $attribute;
        }
        return $result;
    }

    /**
     * @param string[] $attributes
     */
    private function collectAttributes(array $node, array &$attributes): void
    {
        $attribute = $node['attribute'] ?? null;
        if (is_string($attribute) && $attribute !== '') {
            $attributes[] = $attribute;
        }
        $children = $node['conditions'] ?? null;
        if (!is_array($children)) {
            return;
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                $this->collectAttributes($child, $attributes);
            }
        }
    }
}
