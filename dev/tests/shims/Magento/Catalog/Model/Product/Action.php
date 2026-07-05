<?php
declare(strict_types=1);

namespace Magento\Catalog\Model\Product;

/**
 * Minimal shim for Magento\Catalog\Model\Product\Action.
 *
 * Signature matches the real (untyped, no return type) method so doubles that
 * `extends` this load under both the shim runner and real Magento.
 */
class Action
{
    public function updateAttributes($productIds, $attrData, $storeId)
    {
    }
}
