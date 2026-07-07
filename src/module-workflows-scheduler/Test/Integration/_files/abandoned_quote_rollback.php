<?php
declare(strict_types=1);

use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var QuoteResource $quoteResource */
$quoteResource = $objectManager->get(QuoteResource::class);
$quote = $objectManager->get(QuoteFactory::class)->create();
$quoteResource->load($quote, 'wf-abandoned-cart', 'reserved_order_id');
if ($quote->getId()) {
    $quoteResource->delete($quote);
}
