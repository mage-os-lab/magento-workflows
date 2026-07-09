<?php
declare(strict_types=1);

namespace Magento\Catalog\Model;

use Magento\Framework\DataObject;

/**
 * Standalone-runner shim for Magento\Catalog\Model\Product: a DataObject-backed
 * data bag with the product entity code. Test doubles (Test/Unit/Stub/
 * FakeProduct) extend it and override the lifecycle accessors they exercise
 * (isObjectNew / hasDataChanges / getOrigData / get|setWebsiteIds), which the
 * real model provides through AbstractModel.
 */
class Product extends DataObject
{
    public const ENTITY = 'catalog_product';
}
