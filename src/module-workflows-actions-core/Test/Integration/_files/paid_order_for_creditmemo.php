<?php
/**
 * Fixture (docs/20 §5, #18): a fully-invoiced, paid order whose item totals are
 * INTERNALLY CONSISTENT with its grand total, so a single offline "refund all
 * refundable items" credit memo fully refunds the order and canCreditmemo()
 * flips to false — letting order.create_creditmemo demonstrate the
 * refund-then-skip idempotency guard.
 *
 * The core Magento/Sales/_files/invoice.php order forces grand_total 100 onto a
 * single item whose row_total is only the product price, so one offline refund
 * leaves a residual refundable balance and canCreditmemo() stays true — a second
 * execute then refunds again instead of skipping. This fixture keeps every
 * amount aligned (2 x 10 = 20 everywhere) to avoid that.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_WorkflowsActionsCore::Test/Integration/_files/paid_order_for_creditmemo.php
 *
 * Locate the order by increment id '100000001'.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\InvoiceManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento/Sales/_files/default_rollback.php');
Resolver::getInstance()->requireDataFixture('Magento/Catalog/_files/product_simple.php');

$objectManager = Bootstrap::getObjectManager();

/** @var ProductRepositoryInterface $productRepository */
$productRepository = $objectManager->create(ProductRepositoryInterface::class);
$product = $productRepository->get('simple');

$addressData = [
    'region' => 'CA',
    'region_id' => '12',
    'postcode' => '11111',
    'lastname' => 'lastname',
    'firstname' => 'firstname',
    'street' => 'street',
    'city' => 'Los Angeles',
    'email' => 'admin@example.com',
    'telephone' => '11111111',
    'country_id' => 'US',
];

$billingAddress = $objectManager->create(OrderAddress::class, ['data' => $addressData]);
$billingAddress->setAddressType('billing');

$shippingAddress = clone $billingAddress;
$shippingAddress->setId(null)->setAddressType('shipping');

/** @var Payment $payment */
$payment = $objectManager->create(Payment::class);
$payment->setMethod('checkmo');

$price = (float)$product->getPrice(); // 10
$qty = 2;
$rowTotal = $price * $qty; // 20 — kept consistent across item and order totals

/** @var OrderItem $orderItem */
$orderItem = $objectManager->create(OrderItem::class);
$orderItem->setProductId($product->getId())
    ->setQtyOrdered($qty)
    ->setBasePrice($price)
    ->setPrice($price)
    ->setRowTotal($rowTotal)
    ->setBaseRowTotal($rowTotal)
    ->setProductType('simple')
    ->setName($product->getName())
    ->setSku($product->getSku());

/** @var Order $order */
$order = $objectManager->create(Order::class);
$order->setIncrementId('100000001')
    ->setState(Order::STATE_PROCESSING)
    ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
    ->setSubtotal($rowTotal)
    ->setBaseSubtotal($rowTotal)
    ->setGrandTotal($rowTotal)
    ->setBaseGrandTotal($rowTotal)
    ->setBaseToGlobalRate(1)
    ->setBaseToOrderRate(1)
    ->setOrderCurrencyCode('USD')
    ->setBaseCurrencyCode('USD')
    ->setCustomerIsGuest(true)
    ->setCustomerEmail('customer@example.com')
    ->setBillingAddress($billingAddress)
    ->setShippingAddress($shippingAddress)
    ->setStoreId($objectManager->get(StoreManagerInterface::class)->getStore()->getId())
    ->addItem($orderItem)
    ->setPayment($payment);

/** @var OrderRepositoryInterface $orderRepository */
$orderRepository = $objectManager->create(OrderRepositoryInterface::class);
$orderRepository->save($order);

// Fully invoice so the order is paid and every item is refundable offline.
/** @var InvoiceManagementInterface $invoiceManagement */
$invoiceManagement = $objectManager->create(InvoiceManagementInterface::class);
$invoice = $invoiceManagement->prepareInvoice($order);
$invoice->register();
$order = $invoice->getOrder();
$order->setIsInProcess(true);

/** @var Transaction $transaction */
$transaction = $objectManager->create(Transaction::class);
$transaction->addObject($invoice)->addObject($order)->save();
