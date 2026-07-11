<?php
declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Product\Attribute;

/**
 * Minimal shim for the product-attribute collection factory. Real Magento
 * returns a DB-backed collection; the standalone runner only needs the class
 * to exist so conditions that type-hint it can be constructed (tests inject a
 * double whose create() returns an empty/throwing collection).
 */
class CollectionFactory
{
    public function create()
    {
        return null;
    }
}
