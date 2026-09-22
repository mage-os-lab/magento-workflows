<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Model\DryRun;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use MageOS\Workflows\Api\RecentEntityProviderInterface;

/**
 * Recent products for the dry-run entity picker (03): newest first, labelled by
 * sku and name. v1 does no condition filtering — it is a convenience shortcut,
 * and manual id entry remains available for anything not in the list (same
 * contract as the sales_order provider).
 */
class ProductRecentEntityProvider implements RecentEntityProviderInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    public function getEntityType(): string
    {
        return 'catalog_product';
    }

    public function getRecent(int $limit): array
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDirection('DESC')
            ->create();

        $criteria = $this->searchCriteriaBuilder
            ->addSortOrder($sortOrder)
            ->setPageSize($limit)
            ->setCurrentPage(1)
            ->create();

        $rows = [];
        foreach ($this->productRepository->getList($criteria)->getItems() as $product) {
            /** @var ProductInterface $product */
            $rows[] = [
                'id' => (int) $product->getId(),
                'label' => sprintf(
                    '%s — %s',
                    (string) $product->getSku(),
                    (string) $product->getName()
                ),
            ];
        }
        return $rows;
    }
}
