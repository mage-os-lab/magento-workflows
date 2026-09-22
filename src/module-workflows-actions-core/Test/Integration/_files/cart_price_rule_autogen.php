<?php
/**
 * Fixture (docs/20 §5, #20): a cart price rule configured for auto-generated
 * specific coupons, so marketing.generate_coupon can create a real coupon
 * from it.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_WorkflowsActionsCore::Test/Integration/_files/cart_price_rule_autogen.php
 *
 * Locate it by name 'WF Autogen Rule'.
 */
declare(strict_types=1);

use Magento\SalesRule\Model\Rule;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var Rule $rule */
$rule = $objectManager->create(Rule::class);
$rule->setName('WF Autogen Rule');
$rule->setDescription('Integration fixture for marketing.generate_coupon');
$rule->setIsActive(1);
$rule->setCustomerGroupIds([0, 1, 2, 3]);
$rule->setWebsiteIds([1]);
$rule->setCouponType(Rule::COUPON_TYPE_SPECIFIC);
$rule->setUseAutoGeneration(1);
$rule->setUsesPerCoupon(1);
$rule->setSimpleAction(Rule::BY_PERCENT_ACTION);
$rule->setDiscountAmount(10);
$rule->setDiscountQty(0);
$rule->setStopRulesProcessing(0);
$rule->setSortOrder(0);
$rule->save();
