<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Module;

/**
 * Standalone-runner shim for Magento\Framework\Module\Dir (directory-type
 * constants only — the surface the tests reference).
 */
class Dir
{
    public const MODULE_BASE_DIR = '';
    public const MODULE_ETC_DIR = 'etc';
    public const MODULE_I18N_DIR = 'i18n';
    public const MODULE_VIEW_DIR = 'view';
    public const MODULE_CONTROLLER_DIR = 'Controller';
    public const MODULE_SETUP_DIR = 'Setup';
}
