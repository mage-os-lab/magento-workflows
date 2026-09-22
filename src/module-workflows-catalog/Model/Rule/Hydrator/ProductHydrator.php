<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Model\Rule\Hydrator;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Model\Rule\Hydrator\EntityDataConverter;
use MageOS\Workflows\Model\Rule\Hydrator\EntityHydratorInterface;

/**
 * catalog_product hydrator: flat product data with EAV attributes,
 * `category_ids` (lazy category links force-loaded) and `website_ids` (lazy
 * website links force-loaded, PRD-C3) at top level, enriched with aggregate
 * attributes contributed to the catalog_product root through
 * AggregateProviderPool (E2) — the inventory pack's stock leaves (qty,
 * is_in_stock, salable_qty; PRD-C1) today, plus whatever later packs register
 * for the product entity type.
 *
 * The aggregates exist ONLY on hydrated products — trigger snapshots come from
 * trigger payloads and never carry them, so conditions on aggregate attributes
 * always classify as needs_hydration and resolve in phase 2. Absent-for-this-
 * product aggregates (e.g. salable_qty when MSI is not installed) are omitted,
 * so they only match the negative operators (fail-toward-false).
 */
class ProductHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly AggregateProviderPool $aggregateProviderPool
    ) {
    }

    public function hydrate(int $entityId): ?DataObject
    {
        try {
            $product = $this->productRepository->getById($entityId);
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        if ($product instanceof \Magento\Catalog\Model\Product) {
            // Force lazy link loads so `category_ids` and `website_ids` are
            // present in data (both back the multiselect special attributes).
            $product->getCategoryIds();
            $product->getWebsiteIds();
        }

        $data = array_merge(
            $this->dataConverter->toFlatArray($product, ProductInterface::class),
            $this->aggregateProviderPool->getAggregates(HydrationProviderInterface::TYPE_PRODUCT, $entityId)
        );

        return $this->dataObjectFactory->create(['data' => $data]);
    }
}
