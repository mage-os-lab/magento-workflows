<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Hydrator;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * catalog_product hydrator: flat product data with EAV attributes and
 * `category_ids` (lazy category links force-loaded) at top level.
 */
class ProductHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory
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
            // Force lazy category link load so `category_ids` is present in data
            $product->getCategoryIds();
        }

        return $this->dataObjectFactory->create([
            'data' => $this->dataConverter->toFlatArray($product, ProductInterface::class),
        ]);
    }
}
