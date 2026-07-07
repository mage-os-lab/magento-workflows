<?php
declare(strict_types=1);

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;

$objectManager = Bootstrap::getObjectManager();
$repository = $objectManager->get(WorkflowRepositoryInterface::class);
$searchCriteria = $objectManager->create(SearchCriteriaBuilder::class)
    ->addFilter('name', 'Import suppression dispatch fixture')
    ->create();

foreach ($repository->getList($searchCriteria)->getItems() as $workflow) {
    $repository->delete($workflow);
}
