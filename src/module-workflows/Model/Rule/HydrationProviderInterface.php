<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\DataObject;

/**
 * Phase-2 hydration entry point of the two-phase condition evaluation
 * (docs/06-conditions.md).
 *
 * Phase 1 evaluates against the trigger payload snapshot with zero queries.
 * Only when a condition references an attribute missing from the snapshot is
 * this provider asked to hydrate the real entity through its repository.
 *
 * Implementations keep a per-(entityType, entityId) identity map so a
 * condition tree referencing five out-of-snapshot attributes of the same
 * order costs one repository load, not five. Passing $fresh = true bypasses
 * the identity map and re-reads through the repository — used by post-delay
 * re-validation (`revalidate_entity: true`), where the world may have moved.
 *
 * Convention keys (the KEY_* constants below): the ConditionEvaluator places
 * the provider and the execution's entity coordinates onto the DataObject
 * being validated, so any condition deep in the tree can reach the hydration
 * machinery without bespoke wiring. Combines that traverse to related
 * entities (order → customer, order → items → product) propagate these keys
 * onto the derived models with adjusted type/id.
 */
interface HydrationProviderInterface
{
    /**
     * Data keys carried on validated DataObject models (see class docblock)
     */
    public const KEY_PROVIDER = '__hydration_provider';
    public const KEY_ENTITY_TYPE = '__entity_type';
    public const KEY_ENTITY_ID = '__entity_id';
    public const KEY_FRESH = '__hydration_fresh';

    /**
     * Canonical workflow entity type codes
     */
    public const TYPE_ORDER = 'sales_order';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_PRODUCT = 'catalog_product';

    /**
     * Load an entity as a flat DataObject (EAV/custom/extension attributes
     * merged to top level), or null when it no longer exists.
     *
     * @param bool $fresh bypass the identity map and force a repository re-read
     */
    public function getEntity(string $entityType, int $entityId, bool $fresh = false): ?DataObject;
}
