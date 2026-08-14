<?php
declare(strict_types=1);

namespace Magento\SalesRule\Api\Data;

/**
 * Standalone-runner shim for the generated
 * Magento\SalesRule\Api\Data\CouponInterfaceFactory. Marker only:
 * marketing.generate_coupon type-hints it to build the coupon it saves, and
 * test doubles subclass it to return a recording coupon. The real factory is
 * code-generated; create() throws here so an unmocked use surfaces.
 *
 * @method \Magento\SalesRule\Api\Data\CouponInterface create(array $data = [])
 */
class CouponInterfaceFactory
{
    /**
     * @param array $data
     * @return mixed
     */
    public function create(array $data = [])
    {
        throw new \RuntimeException('create() not implemented in shim');
    }
}
