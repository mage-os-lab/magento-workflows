<?php
declare(strict_types=1);

namespace Magento\Catalog\Api;

/**
 * Standalone-runner shim for Magento\Catalog\Api\ProductRepositoryInterface.
 * Mirrors the FULL real interface (6 methods) with the real UNTYPED signatures
 * so a partial double is caught here, not only under real Magento.
 */
interface ProductRepositoryInterface
{
    public function save($product, $saveOptions = false);

    public function get($sku, $editMode = false, $storeId = null, $forceReload = false);

    public function getById($productId, $editMode = false, $storeId = null, $forceReload = false);

    public function getList($searchCriteria);

    public function delete($product);

    public function deleteById($sku);
}
