<?php
/**
 * Fixture (docs/20 §5, #15): one inactive ("abandoned") quote with stored
 * flat totals so the Quote condition can evaluate through phase-2 hydration
 * (QuoteHydrator via CartRepositoryInterface). Inactive so CartRepository::get
 * returns the stored totals rather than recollecting an empty cart to zero.
 *
 *   @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/quote_abandoned.php
 *
 * Locate it by reserved_order_id 'wf-quote-01'.
 */
declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var Quote $quote */
$quote = $objectManager->create(Quote::class);
$quote->setStoreId(1);
$quote->setIsActive(false);
$quote->setIsMultiShipping(false);
$quote->setReservedOrderId('wf-quote-01');
$quote->setCustomerEmail('cart.shopper@example.com');
$quote->setCustomerIsGuest(true);
$quote->setQuoteCurrencyCode('USD');
$quote->setBaseCurrencyCode('USD');
$quote->setStoreCurrencyCode('USD');
$quote->setGrandTotal(150.00);
$quote->setBaseGrandTotal(150.00);
$quote->setSubtotal(150.00);
$quote->setBaseSubtotal(150.00);
$quote->setItemsCount(2);
$quote->setItemsQty(2);
$quote->save();
