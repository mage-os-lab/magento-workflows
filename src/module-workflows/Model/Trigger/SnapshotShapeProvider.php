<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Trigger;

/**
 * Which attribute codes an entity type's trigger snapshot carries (F4 input).
 *
 * The AttributeClassifier is deliberately pure — it takes the snapshot
 * attribute list as an argument rather than discovering it. This provider is
 * that source of truth: a di.xml-configured map of entity type => attribute
 * codes present in the event snapshot for that entity's triggers.
 *
 * An entity type with no configured entry returns null ("shape unknown"): the
 * batch ProfileCheck then enforces only the hydration-forcing node-type
 * constraint (cross-entity/relation conditions), skipping per-attribute
 * verification it cannot perform — never silently passing a cross-entity
 * condition, only declining to second-guess plain attributes whose snapshot
 * membership it cannot see.
 */
class SnapshotShapeProvider
{
    /**
     * @param array<string, string[]> $attributesByEntityType entity type =>
     *        snapshot attribute codes
     */
    public function __construct(
        private readonly array $attributesByEntityType = []
    ) {
    }

    /**
     * @return string[]|null attribute codes, or null when the shape is not declared
     */
    public function getAttributes(string $entityType): ?array
    {
        if ($entityType === '' || !isset($this->attributesByEntityType[$entityType])) {
            return null;
        }
        $attributes = $this->attributesByEntityType[$entityType];
        if (!is_array($attributes)) {
            return null;
        }
        return array_values(array_filter(
            $attributes,
            static fn ($attribute): bool => is_string($attribute) && $attribute !== ''
        ));
    }
}
