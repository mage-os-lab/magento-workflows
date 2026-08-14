<?php
declare(strict_types=1);

namespace Magento\SalesRule\Api;

/**
 * Standalone-runner shim for Magento\SalesRule\Api\CouponRepositoryInterface
 * (the full real surface, untyped as in Magento). save() and getList() are the
 * exercised ones: marketing.generate_coupon looks a deterministic code up
 * before creating it, and creates through save().
 */
interface CouponRepositoryInterface
{
    public function save(\Magento\SalesRule\Api\Data\CouponInterface $coupon);

    public function getById($couponId);

    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);

    public function delete(\Magento\SalesRule\Api\Data\CouponInterface $coupon);

    public function deleteById($couponId);
}
