<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\App\Action;

/**
 * Standalone-runner shim: marker interface implemented by POST admin
 * controllers. Empty by design — the tests only need it to be loadable so the
 * implementing controller class can be defined.
 */
interface HttpPostActionInterface
{
}
