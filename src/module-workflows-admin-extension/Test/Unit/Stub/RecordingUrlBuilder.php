<?php
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
}
