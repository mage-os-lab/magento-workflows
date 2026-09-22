<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Module;

/**
 * Standalone-runner shim for Magento\Framework\Module\Manager. Tests inject a
 * subclass overriding isEnabled() to declare which modules are "installed".
 */
class Manager
{
    /**
     * @param string $moduleName
     * @return bool
     */
    public function isEnabled($moduleName)
    {
        return false;
    }
}
