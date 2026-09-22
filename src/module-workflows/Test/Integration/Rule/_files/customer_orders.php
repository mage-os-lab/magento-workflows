<?php
/**
 * Fixture (docs/20 §5, #15): two non-canceled sales_order rows for the core
 * customer fixture (customer@example.com), so the Customer condition's
 * order-history aggregates (orders_count / lifetime_sales / avg_order_value,
 * computed by CustomerAggregateProvider) evaluate against seeded orders.
 *
 * Pair with Magento/Customer/_files/customer.php. Referenced as:
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 *
 *   @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/customer_orders.php
 *
 * Rows are inserted directly through the sales connection (the aggregate SQL
 * reads only customer_id / base_grand_total / state / created_at), avoiding a
 * full order build.
 */
declare(strict_types=1);

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var CustomerRepositoryInterface $customerRepository */
$customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
$customer = $customerRepository->get('customer@example.com');
$customerId = (int)$customer->getId();

/** @var ResourceConnection $resource */
$resource = $objectManager->get(ResourceConnection::class);
$connection = $resource->getConnection('sales');
$table = $resource->getTableName('sales_order', 'sales');

foreach ([100.00, 200.00] as $index => $total) {
    $connection->insert($table, [
        'state' => 'processing',
        'status' => 'processing',
        'store_id' => 1,
        'customer_id' => $customerId,
        'customer_is_guest' => 0,
        'base_grand_total' => $total,
        'grand_total' => $total,
        'increment_id' => sprintf('WF90000%02d', $index + 1),
        'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 1) . ' days')),
        'updated_at' => date('Y-m-d H:i:s', strtotime('-' . ($index + 1) . ' days')),
    ]);
}
