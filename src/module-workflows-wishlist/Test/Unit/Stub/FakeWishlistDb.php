<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Stub;

use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeQuoteDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeSelect;

/**
 * In-memory wishlist / wishlist_item pair for the two consumers of those
 * tables: ProductWishlistedCustomers (WSH-C1, customers who wishlisted a
 * product) and CustomerWishlistAggregateProvider (CUS-C3, a customer's
 * wishlist item count).
 *
 * Extends FakeQuoteDb ONLY to inherit its signature-faithful AdapterInterface
 * method surface (the ~90 throwing stubs); it overrides the two read methods
 * these two classes actually touch. Each query is evaluated against the WHERE
 * fragments the production code really builds — the relation binds
 * "wi.product_id = ?" and "w.customer_id > 0", the aggregate binds
 * "w.customer_id = ?" — so the semantics under test are the production code's,
 * not the fake's.
 */
class FakeWishlistDb extends FakeQuoteDb
{
    /** @var array<int, array{customer_id: int, product_id: int, store_id: int}> */
    public array $items = [];

    public function addWishlistItem(int $customerId, int $productId, int $storeId = 1): void
    {
        $this->items[] = [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'store_id' => $storeId,
        ];
    }

    /**
     * WSH-C1 relation: distinct customer ids whose wishlist holds the queried
     * product (guest/empty rows excluded when the code binds "w.customer_id > 0").
     *
     * @param FakeSelect $sql
     * @return int[]
     */
    public function fetchCol($sql, $bind = [])
    {
        if (!$sql instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        $productId = (int) $sql->whereValue('wi.product_id');
        $requirePositiveCustomer = $sql->hasWhere('w.customer_id > 0');

        $ids = [];
        foreach ($this->items as $row) {
            if ($row['product_id'] !== $productId) {
                continue;
            }
            if ($requirePositiveCustomer && $row['customer_id'] <= 0) {
                continue;
            }
            $ids[$row['customer_id']] = $row['customer_id'];
        }
        return array_values($ids);
    }

    /**
     * CUS-C3 aggregate: total wishlist items for the queried customer (a real 0
     * when the customer has no wishlist).
     *
     * @param FakeSelect $sql
     * @return string
     */
    public function fetchOne($sql, $bind = [])
    {
        if (!$sql instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        $customerId = (int) $sql->whereValue('w.customer_id');
        $count = 0;
        foreach ($this->items as $row) {
            if ($row['customer_id'] === $customerId) {
                $count++;
            }
        }
        // Zend fetchOne() returns a scalar string; COUNT(*) is always numeric.
        return (string) $count;
    }
}
