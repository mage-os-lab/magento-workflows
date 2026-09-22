<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Model\Relation\Resolver;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * `product.wishlisted_customers` (WSH-C1) — the customers who have this product
 * in a wishlist. This is the fan-out enabler for price-drop / back-in-stock
 * flows: combine catalog's price_changed or inventory's `inventory.back_in_stock`
 * trigger (entity = catalog_product) with fan-out over this relation, and every
 * wishlisting customer gets its own execution.
 *
 * QUERY ROUTE — direct wishlist / wishlist_item read (documented, mirroring the
 * newsletter pack's honesty about the absent SubscriberRepository): Magento
 * exposes NO repository or service contract for "which customers wishlisted
 * product X". The wishlist collections resolve the inverse direction (a
 * customer's items), and the item resource carries no customer column. So the
 * only honest route is a direct connection read joining `wishlist_item`
 * (product_id filter) to `wishlist` (customer_id) — the two tables the Wishlist
 * module owns. Guest/empty rows (customer_id <= 0) are excluded; there are no
 * guest wishlists in core, but the guard keeps a programmatically-created
 * customer_id-less row from resolving to customer 0.
 *
 * WEBSITE SCOPING — deliberately NOT applied in v1 (documented limitation, not
 * an oversight): the wishlist schema is not website-partitioned (the `wishlist`
 * row has no website/store column; only `wishlist_item.store_id` exists, and one
 * customer's single wishlist can span store views). Correct per-website scoping
 * belongs on the customer join (accounts are website-partitioned under
 * per-website account sharing) and would couple this pack to the customer table
 * for a refinement the RelationContext cap already bounds. The $websiteId the
 * context derives is therefore accepted (interface contract) but intentionally
 * unused here.
 *
 * SAFETY — never resolve raw: feature code calls this only through
 * RelationContext::resolve(), which memoizes, applies the resolution cap
 * (mageos_workflows/guards/relation_cap, default 100) and fails toward false on
 * any thrown error. Returning the full set here is correct — the context caps a
 * viral-product fan-out to the configured ceiling and logs the truncation.
 */
class ProductWishlistedCustomers implements RelationInterface
{
    private const WISHLIST_TABLE = 'wishlist';
    private const WISHLIST_ITEM_TABLE = 'wishlist_item';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getCode(): string
    {
        return 'product.wishlisted_customers';
    }

    public function getLabel(): string
    {
        return (string) __('customers who wishlisted this product');
    }

    public function getSourceEntityType(): string
    {
        return HydrationProviderInterface::TYPE_PRODUCT;
    }

    public function getTargetEntityType(): string
    {
        return HydrationProviderInterface::TYPE_CUSTOMER;
    }

    public function getCardinality(): string
    {
        return self::CARDINALITY_MANY;
    }

    public function resolveIds(DataObject $source, ?int $websiteId): array
    {
        $productId = (int) ($source->getData('entity_id') ?: $source->getData('product_id') ?: 0);
        if ($productId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['wi' => $this->resourceConnection->getTableName(self::WISHLIST_ITEM_TABLE)], [])
            ->join(
                ['w' => $this->resourceConnection->getTableName(self::WISHLIST_TABLE)],
                'w.wishlist_id = wi.wishlist_id',
                ['customer_id']
            )
            ->where('wi.product_id = ?', $productId)
            ->where('w.customer_id > 0');

        // RelationContext normalizes (intval + array_unique) and caps; a customer
        // with the same product in several wishlist items dedupes there.
        return array_map('intval', $connection->fetchCol($select));
    }
}
