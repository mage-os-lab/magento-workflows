<?php
declare(strict_types=1);

namespace Magento\CatalogInventory\Api;

/**
 * Minimal shim for the legacy CatalogInventory stock registry. Only the
 * getStockItem() seam the workflow stock aggregates read is declared; the
 * returned stock item is duck-typed (getQty()/getIsInStock()) so tests can
 * return a lightweight double.
 */
interface StockRegistryInterface
{
    public function getStockItem($productId, $scopeId = null);
}
