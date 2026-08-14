<?php
declare(strict_types=1);

namespace Magento\SalesRule\Api;

/**
 * Standalone-runner shim for Magento\SalesRule\Api\RuleRepositoryInterface.
 * Mirrors the real interface's four methods with their real UNTYPED
 * signatures, so a test double satisfies the same contract it faces under real
 * Magento. Only getById() is exercised (marketing.generate_coupon loads the
 * rule to check auto-generation and copy its expiry).
 */
interface RuleRepositoryInterface
{
    public function save(\Magento\SalesRule\Api\Data\RuleInterface $rule);

    public function getById($ruleId);

    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria);

    public function deleteById($ruleId);
}
