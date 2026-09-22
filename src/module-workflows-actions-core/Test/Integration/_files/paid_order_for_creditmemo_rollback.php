<?php
/**
 * Rollback for paid_order_for_creditmemo.php. Under @magentoDbIsolation the
 * enclosing transaction already rolls everything back; this keeps the fixture
 * usable when isolation is off by delegating to the core order rollback (which
 * removes any order/invoice/creditmemo rows) and the product rollback.
 *
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento/Sales/_files/default_rollback.php');
Resolver::getInstance()->requireDataFixture('Magento/Catalog/_files/product_simple_rollback.php');
