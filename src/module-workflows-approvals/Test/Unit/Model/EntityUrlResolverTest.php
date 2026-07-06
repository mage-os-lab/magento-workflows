<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use Magento\Framework\UrlInterface;
use MageOS\WorkflowsApprovals\Model\EntityUrlResolver;
use PHPUnit\Framework\TestCase;

class EntityUrlResolverTest extends TestCase
{
    private function urlBuilder(): UrlInterface
    {
        return new class implements UrlInterface {
            public function getUrl($routePath = null, $routeParams = null)
            {
                return 'https://admin.test/' . $routePath . '/' . http_build_query((array) $routeParams);
            }

            public function getUseSession()
            {
                return false;
            }

            public function getBaseUrl($params = [])
            {
                return '';
            }

            public function getCurrentUrl()
            {
                return '';
            }

            public function getRouteUrl($routePath = null, $routeParams = null)
            {
                return '';
            }

            public function addSessionParam()
            {
                return $this;
            }

            public function addQueryParams(array $data)
            {
                return $this;
            }

            public function setQueryParam($key, $data)
            {
                return $this;
            }

            public function escape($value)
            {
                return '';
            }

            public function getDirectUrl($url, $params = [])
            {
                return '';
            }

            public function sessionUrlVar($html)
            {
                return '';
            }

            public function isOwnOriginUrl()
            {
                return false;
            }

            public function getRedirectUrl($url)
            {
                return '';
            }

            public function setScope($params)
            {
                return $this;
            }
        };
    }

    public function testKnownEntityTypeBuildsUrl(): void
    {
        $resolver = new EntityUrlResolver(
            ['sales_order' => ['route' => 'sales/order/view', 'param' => 'order_id']],
            $this->urlBuilder()
        );

        $url = $resolver->getUrl('sales_order', 123);

        $this->assertSame('https://admin.test/sales/order/view/order_id=123', $url);
    }

    public function testUnknownEntityTypeReturnsNull(): void
    {
        $resolver = new EntityUrlResolver([], $this->urlBuilder());
        $this->assertNull($resolver->getUrl('mystery_type', 1));
    }

    public function testZeroEntityIdReturnsNull(): void
    {
        $resolver = new EntityUrlResolver(
            ['sales_order' => ['route' => 'sales/order/view', 'param' => 'order_id']],
            $this->urlBuilder()
        );
        $this->assertNull($resolver->getUrl('sales_order', 0));
    }

    public function testMalformedMapEntryReturnsNull(): void
    {
        $resolver = new EntityUrlResolver(['sales_order' => ['route' => 'sales/order/view']], $this->urlBuilder());
        $this->assertNull($resolver->getUrl('sales_order', 5));
    }
}
