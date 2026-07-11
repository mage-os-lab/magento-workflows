<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Stub;

use Magento\Catalog\Model\Product;

/**
 * Product stand-in for the catalog_product_save_after observer and the
 * website-assignment action. Carries current data, ORIGINAL data (empty models
 * a freshly created product), the new/changed flags, and the website links.
 * Skips the real Product constructor (mirrors Test/Unit/Stub/FakeReview);
 * accessors are self-contained.
 */
class FakeProduct extends Product
{
    /**
     * @param array<string, mixed> $data current data (price/status/type_id/...)
     * @param array<string, mixed> $origData original data; [] = newly created
     * @param int[] $websiteIds current website membership
     */
    public function __construct(
        private int $id = 1,
        private string $sku = 'SKU-1',
        private array $data = [],
        private array $origData = [],
        private bool $isNew = false,
        private bool $hasChanges = true,
        private array $websiteIds = [],
        private int $storeId = 0
    ) {
    }

    public function getId()
    {
        return $this->id;
    }

    public function getSku()
    {
        return $this->sku;
    }

    public function getStoreId()
    {
        return $this->storeId;
    }

    public function getTypeId()
    {
        return $this->data['type_id'] ?? 'simple';
    }

    public function getStatus()
    {
        return $this->data['status'] ?? null;
    }

    public function getData($key = '', $index = null)
    {
        if ($key === '') {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }

    public function getOrigData($key = null)
    {
        if ($key === null) {
            return $this->origData;
        }
        return $this->origData[$key] ?? null;
    }

    public function isObjectNew($flag = null)
    {
        return $this->isNew;
    }

    public function hasDataChanges()
    {
        return $this->hasChanges;
    }

    public function getWebsiteIds()
    {
        return $this->websiteIds;
    }

    public function setWebsiteIds($websiteIds)
    {
        $this->websiteIds = $websiteIds;
        return $this;
    }
}
