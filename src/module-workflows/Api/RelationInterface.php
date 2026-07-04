<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use Magento\Framework\DataObject;

/**
 * One directed entity relation (F5, docs/discovery/entity-cross-referencing.md):
 * "from a source entity, which target-entity ids are related?" Register
 * implementations into MageOS\Workflows\Model\Relation\RelationPool via
 * di.xml — the pool IS the extension surface, mirroring ActionPool:
 *
 * <type name="MageOS\Workflows\Model\Relation\RelationPool">
 *     <arguments>
 *         <argument name="relations" xsi:type="array">
 *             <item name="order_customer" xsi:type="object">Vendor\Module\Relation\OrderCustomer</item>
 *         </argument>
 *     </arguments>
 * </type>
 *
 * INVARIANT: feature code never calls resolveIds() directly — all resolution
 * goes through RelationContext::resolve(), which centralizes memoization,
 * website scoping, the resolution cap, and the fail-toward-false rule.
 * A consumer bypassing the context silently loses exactly those guarantees.
 *
 * @api
 */
interface RelationInterface
{
    public const CARDINALITY_ONE = 'one';
    public const CARDINALITY_MANY = 'many';

    /**
     * Stable relation code, e.g. "order_customer"
     */
    public function getCode(): string;

    /**
     * Merchant-facing label, e.g. "the order's customer"
     */
    public function getLabel(): string;

    /**
     * Source workflow entity type, e.g. "sales_order"
     */
    public function getSourceEntityType(): string;

    /**
     * Target workflow entity type, e.g. "customer"
     */
    public function getTargetEntityType(): string;

    /**
     * self::CARDINALITY_ONE | self::CARDINALITY_MANY
     */
    public function getCardinality(): string;

    /**
     * Resolve related target-entity ids for the given source entity.
     * Context-internal: called only by RelationContext (see class docblock).
     *
     * @param DataObject $source flat source-entity data (trigger snapshot or hydrated)
     * @param int|null $websiteId website scope to restrict the lookup to; null = unscoped
     * @return int[] target entity ids; empty when nothing is related
     */
    public function resolveIds(DataObject $source, ?int $websiteId): array;
}
