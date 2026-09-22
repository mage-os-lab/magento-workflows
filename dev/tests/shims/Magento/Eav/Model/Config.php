<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Eav\Model;

/**
 * Minimal shim for Magento\Eav\Model\Config. Only needs to exist and be
 * instantiable so EAV-introspecting conditions that type-hint it can be
 * constructed under the standalone runner; the methods return neutral values
 * (tests exercise non-EAV code paths).
 */
class Config
{
    public function getAttribute($entityType, $code = null)
    {
        return null;
    }

    public function getEntityType($code)
    {
        return null;
    }
}
