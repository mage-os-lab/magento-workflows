<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\Product\Attribute\Source;

/**
 * Standalone-runner shim for the product status source: the enabled/disabled
 * status constants the ProductSaveObserver maps to its status_changed labels.
 */
class Status
{
    public const STATUS_ENABLED = 1;
    public const STATUS_DISABLED = 2;
}
