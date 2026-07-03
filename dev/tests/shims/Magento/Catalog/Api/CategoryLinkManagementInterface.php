<?php
declare(strict_types=1);

namespace Magento\Catalog\Api;

interface CategoryLinkManagementInterface
{
    public function assignProductToCategories(string $sku, array $categoryIds);
}
