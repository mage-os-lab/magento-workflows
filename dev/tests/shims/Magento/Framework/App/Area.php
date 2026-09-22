<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App;

/**
 * Standalone-runner shim for Magento\Framework\App\Area. Only the constant
 * ParkNotifier/notify.email reference is declared.
 */
class Area
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_ADMINHTML = 'adminhtml';
    public const AREA_GLOBAL = 'global';
}
