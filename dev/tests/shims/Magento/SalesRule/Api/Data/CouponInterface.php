<?php
declare(strict_types=1);

namespace Magento\SalesRule\Api\Data;

/**
 * Standalone-runner shim for Magento\SalesRule\Api\Data\CouponInterface.
 *
 * Carries the two REAL type constants (TYPE_MANUAL = 0, TYPE_GENERATED = 1) —
 * marketing.generate_coupon must mark its coupon generated, since
 * CouponRepository::save() rejects a manual coupon on an auto-generation rule
 * — plus the accessors the action sets/reads.
 */
interface CouponInterface
{
    public const TYPE_MANUAL = 0;
    public const TYPE_GENERATED = 1;

    public function getCouponId();

    public function setCouponId($couponId);

    public function getRuleId();

    public function setRuleId($ruleId);

    public function getCode();

    public function setCode($code);

    public function getExpirationDate();

    public function setExpirationDate($expirationDate);

    public function getCreatedAt();

    public function setCreatedAt($createdAt);

    public function getType();

    public function setType($type);
}
