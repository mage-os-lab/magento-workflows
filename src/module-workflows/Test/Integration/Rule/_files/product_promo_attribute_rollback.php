<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var EavSetup $eavSetup */
$eavSetup = $objectManager->create(EavSetupFactory::class)->create();

try {
    $eavSetup->removeAttribute(Product::ENTITY, 'wf_promo_flag');
} catch (\Exception $e) {
    unset($e);
}
