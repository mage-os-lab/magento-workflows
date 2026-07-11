<?php
/**
 * Fixture (docs/20-integration-test-plan.md §6, suite #24c): one active guest
 * quote carrying the simple product and a customer email — the raw material
 * AbandonedCartDetector queries. Requires Magento/Catalog/_files/product_simple.php
 * to have run first. The test rewinds `updated_at` (and toggles is_active /
 * items_count for the exclusion cases) with direct UPDATEs — no sleeps (§2.4).
 *
 * Look it up by reserved_order_id 'wf-abandoned-cart'.
 */
declare(strict_types=1);

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Catalog\Api\ProductRepositoryInterface;

$objectManager = Bootstrap::getObjectManager();
$product = $objectManager->get(ProductRepositoryInterface::class)->get('simple');

/** @var Quote $quote */
$quote = $objectManager->create(Quote::class);
$quote->setStoreId(1);
$quote->setIsActive(true);
$quote->setIsMultiShipping(false);
$quote->setReservedOrderId('wf-abandoned-cart');
$quote->setCustomerEmail('abandoned-cart@example.com');
$quote->setCustomerIsGuest(true);
$quote->addProduct($product, 1);
$quote->setItemsCount(1);
$quote->setItemsQty(1);
$quote->collectTotals();

$objectManager->get(CartRepositoryInterface::class)->save($quote);

// Guarantee the detector's stored-column preconditions regardless of quote
// model bookkeeping: an active, non-empty cart with an email.
$resource = $objectManager->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$connection->update(
    $resource->getTableName('quote'),
    ['items_count' => 1, 'is_active' => 1, 'customer_email' => 'abandoned-cart@example.com'],
    ['entity_id = ?' => (int) $quote->getId()]
);
