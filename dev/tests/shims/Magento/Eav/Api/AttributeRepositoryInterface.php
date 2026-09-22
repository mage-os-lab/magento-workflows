<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Eav\Api;

/**
 * Standalone-runner shim for Magento\Eav\Api\AttributeRepositoryInterface.
 * Params are untyped like the real Magento contract; only the methods the
 * SetAttribute actions/tests exercise are declared (test doubles implement
 * the full five-method real surface so they also load under real Magento).
 */
interface AttributeRepositoryInterface
{
    public function get($entityTypeCode, $attributeCode);
}
