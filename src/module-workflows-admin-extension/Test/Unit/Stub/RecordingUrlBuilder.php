<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminExtension\Test\Unit\Stub;

use Magento\Framework\UrlInterface;

/**
 * UrlInterface stand-in that records every getUrl() route + params so a test can
 * assert the deep-link structure (e.g. the filters_modifier payload).
 */
class RecordingUrlBuilder implements UrlInterface
{
    /** @var array<int, array{route: mixed, params: mixed}> */
    public array $calls = [];

    public function getUrl($routePath = null, $routeParams = null)
    {
        $this->calls[] = ['route' => $routePath, 'params' => $routeParams];
        return 'https://admin.example/' . (string) $routePath;
    }

    public function getUseSession()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getBaseUrl($params = [])
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getCurrentUrl()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getRouteUrl($routePath = null, $routeParams = null)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function addSessionParam()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function addQueryParams(array $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function setQueryParam($key, $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function escape($value)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDirectUrl($url, $params = [])
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function sessionUrlVar($html)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function isOwnOriginUrl()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getRedirectUrl($url)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function setScope($params)
    {
        throw new \BadMethodCallException(__METHOD__);
    }
}
