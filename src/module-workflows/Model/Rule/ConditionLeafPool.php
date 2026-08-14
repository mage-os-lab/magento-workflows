<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\ObjectManagerInterface;
use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * DI-registered map entity_type => leaf (attribute) condition class — the
 * leaf counterpart of ConditionCombinePool. Domain packs register their
 * entity's leaf class here so a condition in one pack can offer child
 * conditions against another pack's entity WITHOUT a compile-time reference
 * to that pack's classes: the consumer asks the pool by entity-type string
 * and degrades gracefully (offers no such children) when no pack has
 * registered the type — i.e. when that domain pack is not installed.
 *
 * First consumer: the sales pack's Order\ItemsFound, which offers
 * product-attribute child conditions when the catalog pack is present
 * (domain-packs S3 follow-up; docs/discovery/implementation/08-domain-packs.md).
 */
class ConditionLeafPool
{
    /**
     * The ObjectManager is used directly for the same reason as
     * ConditionCombinePool: leaf class names are dynamic data from the DI
     * map, mirroring \Magento\Rule\Model\ConditionFactory.
     *
     * @param array<string, string> $leaves entity_type => leaf condition class name
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly array $leaves = []
    ) {
    }

    /**
     * Leaf condition class name for the entity type, null when no domain
     * pack has registered one.
     */
    public function getLeafClass(string $entityType): ?string
    {
        return $this->leaves[$entityType] ?? null;
    }

    /**
     * Every registered leaf class, all entity types — the allowlist source
     * for validating stored `type` strings before they reach the condition
     * factory (ConditionTypeAllowlist).
     *
     * @return string[]
     */
    public function getClasses(): array
    {
        return array_values($this->leaves);
    }

    /**
     * Create a fresh leaf condition for the entity type, null when
     * unregistered. Always a new instance: conditions are stateful.
     *
     * @throws \InvalidArgumentException when the registered class is not a condition
     */
    public function createLeaf(string $entityType): ?AbstractCondition
    {
        $class = $this->getLeafClass($entityType);
        if ($class === null) {
            return null;
        }
        $leaf = $this->objectManager->create($class);
        if (!$leaf instanceof AbstractCondition) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Workflow condition leaf "%s" for entity type "%s" must extend %s',
                    $class,
                    $entityType,
                    AbstractCondition::class
                )
            );
        }
        return $leaf;
    }
}
