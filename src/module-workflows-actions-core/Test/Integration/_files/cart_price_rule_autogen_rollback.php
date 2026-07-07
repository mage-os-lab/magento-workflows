<?php
declare(strict_types=1);

use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/** @var CollectionFactory $collectionFactory */
$collectionFactory = $objectManager->create(CollectionFactory::class);
$collection = $collectionFactory->create()->addFieldToFilter('name', 'WF Autogen Rule');

/** @var Rule $rule */
foreach ($collection as $rule) {
    $rule->delete();
}
