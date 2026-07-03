<?php
declare(strict_types=1);

namespace Magento\Catalog\Api;

interface ProductRepositoryInterface
{
    public function getById(int $productId);
}
