<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsWishlist\Test\Unit\Stub;

use Magento\Wishlist\Model\Wishlist;

/**
 * Wishlist stand-in for the wishlist_add_product observer: carries the wishlist
 * id and the owning customer id. Skips the real Wishlist constructor in both
 * environments; accessors are self-contained.
 *
 * @param mixed $wishlistId
 * @param mixed $customerId
 */
class FakeWishlist extends Wishlist
{
    public function __construct(
        private $wishlistId = 0,
        private $customerId = 0
    ) {
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->wishlistId;
    }

    /**
     * @return mixed
     */
    public function getCustomerId()
    {
        return $this->customerId;
    }
}
