<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Model\Rule\Hydrator;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * Customer wishlist aggregate (CUS-C3, wishlist half), contributed to the
 * CUSTOMER condition root through AggregateProviderPool under entity type
 * 'customer' — registered ALONGSIDE the sales order-history, customer birthday
 * and newsletter providers (the pool merges every provider per entity type), so
 * "…and has 3+ items on a wishlist" becomes a customer-root guard with ZERO
 * changes to workflows-customer.
 *
 *  - wishlist_items_count: total wishlist items across the customer's wishlist(s)
 *    (numeric). Counted by joining wishlist_item to wishlist on customer_id — the
 *    same two Wishlist-owned tables the WSH-C1 relation reads (no repository
 *    exists; documented there).
 *
 * ZERO vs ABSENT — this attribute is ALWAYS PRESENT with a real value, including
 * 0, and is deliberately NOT absent-when-empty (unlike the order-history /
 * birthday aggregates, which omit their keys when unknowable). A customer with
 * no wishlist genuinely HAS zero wishlist items — that is a knowable, meaningful
 * 0, not a "can't tell" absence. Emitting a real 0 makes both directions
 * correct: "wishlist_items_count >= 1" misses the empty customer, and
 * "wishlist_items_count = 0" (re-engage shoppers who saved nothing) MATCHES
 * them — which an absent key would wrongly skip. The only degenerate absence is
 * a non-positive customer id (never a real customer-root entity), which yields
 * no key.
 *
 * Hydration-time only — never part of a trigger snapshot — so conditions on it
 * always classify as needs_hydration and resolve in phase 2.
 */
class CustomerWishlistAggregateProvider implements AggregateProviderInterface
{
    /**
     * Attribute code => [label, workflow input type]. Label is a raw string
     * ( __()-wrapped by the consuming condition root).
     */
    private const ATTRIBUTE_METADATA = [
        'wishlist_items_count' => ['label' => 'Wishlist Items Count', 'input_type' => 'numeric'],
    ];

    private const WISHLIST_TABLE = 'wishlist';
    private const WISHLIST_ITEM_TABLE = 'wishlist_item';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array{wishlist_items_count?: int}
     */
    public function getAggregates(int $customerId): array
    {
        if ($customerId <= 0) {
            // Not a real customer-root entity: nothing to compute.
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['wi' => $this->resourceConnection->getTableName(self::WISHLIST_ITEM_TABLE)],
                ['items_count' => new Expression('COUNT(*)')]
            )
            ->join(
                ['w' => $this->resourceConnection->getTableName(self::WISHLIST_TABLE)],
                'w.wishlist_id = wi.wishlist_id',
                []
            )
            ->where('w.customer_id = ?', $customerId);

        // COUNT(*) over an empty join is a real 0 — the present, knowable value.
        return ['wishlist_items_count' => (int) $connection->fetchOne($select)];
    }
}
