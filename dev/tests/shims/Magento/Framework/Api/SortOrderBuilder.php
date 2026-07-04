<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\SortOrderBuilder.
 * Returns stub objects; intended for interface verification only.
 */
interface SortOrderBuilder
{
    public function create();

    public function setField($field);

    public function setDirection($direction);
}
