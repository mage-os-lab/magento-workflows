<?php
declare(strict_types=1);

namespace Magento\SalesRule\Api\Data;

/**
 * Standalone-runner shim for Magento\SalesRule\Api\Data\RuleInterface — only
 * the accessors marketing.generate_coupon reads (name, auto-generation flag,
 * expiry). The real interface is far larger; per dev/tests/shims/README.md a
 * shim carries the minimum surface the tests exercise.
 */
interface RuleInterface
{
    public function getRuleId();

    public function getName();

    public function getUseAutoGeneration();

    public function getToDate();

    public function getUsesPerCoupon();

    public function getUsesPerCustomer();
}
