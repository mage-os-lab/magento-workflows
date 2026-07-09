<?php
declare(strict_types=1);

namespace Magento\Wishlist\Model;

use Magento\Framework\DataObject;

/**
 * Standalone-runner shim for Magento\Wishlist\Model\Item — the wishlist line
 * carried under the 'item' key of the 'wishlist_add_product' event. A data-bag
 * body (the real model resolves product_id/wishlist_id/qty/store_id magically
 * through AbstractModel/DataObject, which this shim's DataObject parent
 * mirrors); the pack's test Fake extends it and overrides the accessors it
 * exercises. Real Magento installs load the real class instead.
 */
class Item extends DataObject
{
}
