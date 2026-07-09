<?php
declare(strict_types=1);

namespace Magento\Wishlist\Model;

use Magento\Framework\DataObject;

/**
 * Standalone-runner shim for Magento\Wishlist\Model\Wishlist — the wishlist
 * carried under the 'wishlist' key of the 'wishlist_add_product' event. A
 * data-bag body (the real model resolves customer_id / id magically through
 * AbstractModel/DataObject, mirrored here); the pack's test Fake extends it and
 * overrides the accessors it exercises. Real Magento installs load the real
 * class instead.
 */
class Wishlist extends DataObject
{
}
