<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App;

/**
 * Standalone-runner shim: only getParam() is declared -- the sole method the
 * form DataProvider (and its tests) exercise off the request.
 */
interface RequestInterface
{
    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getParam($key, $default = null);
}
