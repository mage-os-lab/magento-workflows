<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;

/**
 * Gate for the `type` strings a stored condition tree may instantiate.
 *
 * Magento's Combine::loadArray hands every child node's `type` straight to
 * the condition factory, which is an ObjectManager create() of an arbitrary
 * class name from stored data. The trust boundary is ::manage (plus data
 * patches that bypass save-time validation entirely), so the evaluator
 * refuses to load a tree whose `type` names an EXISTING class outside the
 * registered condition surface: the pools' combine and leaf classes, the
 * engine's own related-entity combine and trigger-data leaf, and any classes
 * a third-party pack appends via the di.xml `additional` argument.
 *
 * A `type` that is NOT an existing class is deliberately allowed through:
 * marker strings like "combine" appear in real trees, instantiate nothing
 * (the factory throws internally and the node is skipped), and rejecting
 * them would break stored workflows without closing any hole. The hazard
 * this class exists for is precisely a type that autoloads — constructor
 * side effects fire before any instanceof check can object.
 */
class ConditionTypeAllowlist
{
    /**
     * Engine-owned condition classes that legitimately appear inside trees
     * but are not registered in either pool.
     */
    private const BUILT_IN = [
        RelatedEntityCombine::class,
        TriggerData::class,
    ];

    /** @var array<string, true>|null lazily-built normalized set */
    private ?array $allowed = null;

    /**
     * @param string[] $additional extra allowed classes (di.xml-extensible;
     *        third-party condition packs append their classes here)
     */
    public function __construct(
        private readonly ConditionCombinePool $combinePool,
        private readonly ConditionLeafPool $leafPool,
        private readonly array $additional = []
    ) {
    }

    /**
     * True when this `type` is safe to hand to the condition factory: either
     * it is a registered condition class, or it is not a loadable class at
     * all (an inert marker the factory will reject without instantiating).
     */
    public function isAllowed(string $type): bool
    {
        $normalized = ltrim($type, '\\');
        if (isset($this->allowedSet()[$normalized])) {
            return true;
        }
        return !class_exists($normalized);
    }

    /**
     * @return array<string, true>
     */
    private function allowedSet(): array
    {
        if ($this->allowed === null) {
            $this->allowed = [];
            $classes = array_merge(
                self::BUILT_IN,
                $this->combinePool->getClasses(),
                $this->leafPool->getClasses(),
                $this->additional
            );
            foreach ($classes as $class) {
                $this->allowed[ltrim((string) $class, '\\')] = true;
            }
        }
        return $this->allowed;
    }
}
