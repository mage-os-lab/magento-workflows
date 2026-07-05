<?php
declare(strict_types=1);

namespace Magento\Backend\Model;

/**
 * Standalone-runner shim for Magento\Backend\Model\UrlInterface — the
 * backend-specific URL builder (works regardless of the current request area,
 * e.g. from a queue consumer). Only the getUrl() surface ParkNotifier
 * exercises is declared.
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
