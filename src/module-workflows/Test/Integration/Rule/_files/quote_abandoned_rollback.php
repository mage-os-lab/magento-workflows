<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var Quote $quote */
$quote = $objectManager->create(Quote::class);
$quote->load('wf-quote-01', 'reserved_order_id');
if ($quote->getId()) {
    $quote->delete();
}
