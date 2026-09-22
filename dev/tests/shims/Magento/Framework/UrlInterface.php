<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\UrlInterface. Only the getUrl()
 * surface the grid-strip view model exercises is declared.
 */
interface UrlInterface
{
    /**
     * @param string|null $routePath
     * @param array|null $routeParams
     * @return string
     */
    public function getUrl($routePath = null, $routeParams = null);
}
