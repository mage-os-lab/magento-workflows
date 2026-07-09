<?php
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Stub;

use Magento\Wishlist\Model\Item;

/**
 * Wishlist Item stand-in for the wishlist_add_product observer: carries the
 * item id, product id, wishlist id, qty and store id. Skips the real Item
 * constructor (which pulls a large dependency graph) in both environments;
 * accessors are self-contained, exactly as the review pack's FakeReview does.
 *
 * @param mixed $itemId
 * @param mixed $productId
 * @param mixed $wishlistId
 * @param mixed $qty
 * @param mixed $storeId
 */
class FakeWishlistItem extends Item
{
    public function __construct(
        private $itemId = 0,
        private $productId = 0,
        private $wishlistId = 0,
        private $qty = 1,
        private $storeId = 0
    ) {
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->itemId;
    }

    /**
     * @return mixed
     */
    public function getProductId()
    {
        return $this->productId;
    }

    /**
     * @return mixed
     */
    public function getWishlistId()
    {
        return $this->wishlistId;
    }

    /**
     * @return mixed
     */
    public function getQty()
    {
        return $this->qty;
    }

    /**
     * @return mixed
     */
    public function getStoreId()
    {
        return $this->storeId;
    }
}
