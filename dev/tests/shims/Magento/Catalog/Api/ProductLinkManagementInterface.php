<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Api;

/**
 * Standalone-runner shim for Magento\Catalog\Api\ProductLinkManagementInterface:
 * read links of a type and replace the product's link collection.
 */
interface ProductLinkManagementInterface
{
    public function getLinkedItemsByType($sku, $type);

    public function setProductLinks($sku, array $items);
}
