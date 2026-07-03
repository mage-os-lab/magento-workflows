<?php
declare(strict_types=1);

namespace Magento\Framework\Api;

/**
 * Minimal shim for Magento\Framework\Api\FilterBuilder.
 * Returns stub objects; intended for interface verification only.
 */
interface FilterBuilder
{
    public function create();
    public function setField($field);
    public function setValue($value);
    public function setConditionType($type);
}
