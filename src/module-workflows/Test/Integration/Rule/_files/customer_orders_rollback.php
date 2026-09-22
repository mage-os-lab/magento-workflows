<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var ResourceConnection $resource */
$resource = $objectManager->get(ResourceConnection::class);
$connection = $resource->getConnection('sales');
$table = $resource->getTableName('sales_order', 'sales');

try {
    /** @var CustomerRepositoryInterface $customerRepository */
    $customerRepository = $objectManager->get(CustomerRepositoryInterface::class);
    $customer = $customerRepository->get('customer@example.com');
    $connection->delete($table, ['customer_id = ?' => (int)$customer->getId()]);
} catch (\Exception $e) {
    // Customer already rolled back, or the ids collapsed with the transaction.
    $connection->delete($table, ['increment_id LIKE ?' => 'WF90000%']);
}
