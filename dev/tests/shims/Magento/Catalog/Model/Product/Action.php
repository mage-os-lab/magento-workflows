<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
