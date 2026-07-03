<?php
declare(strict_types=1);

namespace Magento\Framework\Api\Search;

/**
 * Minimal shim for Magento\Framework\Api\Search\FilterGroupBuilder.
 * Returns stub objects; intended for interface verification only.
 */
interface FilterGroupBuilder
{
    public function create();
    public function setFilters($filters);
}
