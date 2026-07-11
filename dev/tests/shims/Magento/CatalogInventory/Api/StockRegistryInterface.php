<?php
declare(strict_types=1);

namespace Magento\CatalogInventory\Api;

/**
 * Standalone-runner shim for the legacy CatalogInventory stock registry.
 * Mirrors the FULL real @api interface (9 methods) so a double must satisfy
 * the same contract it faces under real Magento; the workflow stock aggregates
 * only read getStockItem() (the returned item is duck-typed on
 * getQty()/getIsInStock()), the rest exist so partial doubles are rejected.
 */
interface StockRegistryInterface
{
    public function getStock($scopeId = null);

    public function getStockItem($productId, $scopeId = null);

    public function getStockItemBySku($productSku, $scopeId = null);

    public function getStockStatus($productId, $scopeId = null);

    public function getStockStatusBySku($productSku, $scopeId = null);

    public function getProductStockStatus($productId, $scopeId = null);

    public function getProductStockStatusBySku($productSku, $scopeId = null);

    public function getLowStockItems($scopeId, $qty, $currentPage = 1, $pageSize = 0);

    public function updateStockItemBySku($productSku, $stockItem);
}
