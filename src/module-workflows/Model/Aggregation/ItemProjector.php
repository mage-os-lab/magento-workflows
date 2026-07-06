<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

/**
 * Projects a flat entity snapshot down to the batch item shape: keeps only the
 * configured/derived field set. Keeps batch rows small and bounds the PII
 * surface (docs/10 §PII containment).
 *
 * Default projection (05 §Compatibility): identity fields + the attributes
 * named in the root conditions (walked from the serialized tree's `attribute`
 * keys). Template-referenced fields are deliberately NOT auto-derived — a
 * workflow whose action templates need more declares an explicit `projection`
 * list. An explicit projection is honoured verbatim (plus the identity fields,
 * which always survive so items[] stays addressable).
 */
class ItemProjector
{
    /**
     * Fields always retained so a batch item is identifiable regardless of the
     * projection.
     */
    private const IDENTITY_FIELDS = ['entity_id', 'increment_id', 'store_id'];

    /**
     * Resolve the field set for a workflow once (call per flush/dispatch, not
     * per item).
     *
     * @param string[] $explicitProjection AggregationConfig::getProjection()
     * @return string[] ordered, de-duplicated field allowlist
     */
    public function fieldsFor(array $explicitProjection, ?string $conditionsSerialized): array
    {
        $fields = self::IDENTITY_FIELDS;
        if ($explicitProjection !== []) {
            $fields = array_merge($fields, $explicitProjection);
        } else {
            $fields = array_merge($fields, $this->conditionAttributes($conditionsSerialized));
        }
        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $flatItem
     * @param string[] $fields resolved via fieldsFor()
     * @return array<string, mixed>
     */
    public function project(array $flatItem, array $fields): array
    {
        $projected = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $flatItem)) {
                $projected[$field] = $flatItem[$field];
            }
        }
        return $projected;
    }

    /**
     * Every `attribute` value referenced anywhere in the serialized condition
     * tree.
     *
     * @return string[]
     */
    private function conditionAttributes(?string $conditionsSerialized): array
    {
        if ($conditionsSerialized === null || trim($conditionsSerialized) === '') {
            return [];
        }
        $tree = json_decode($conditionsSerialized, true);
        if (!is_array($tree)) {
            return [];
        }
        $attributes = [];
        $this->collect($tree, $attributes);
        return array_values(array_unique($attributes));
    }

    /**
     * @param string[] $attributes
     */
    private function collect(array $node, array &$attributes): void
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
                $this->collect($child, $attributes);
            }
        }
    }
}
