<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var CustomerSetupFactory $customerSetupFactory */
$customerSetupFactory = $objectManager->create(CustomerSetupFactory::class);
$customerSetup = $customerSetupFactory->create();

try {
    $customerSetup->removeAttribute(Customer::ENTITY, 'wf_loyalty_tier');
} catch (\Exception $e) {
    // Already gone (transaction rollback) — nothing to do.
    unset($e);
}
