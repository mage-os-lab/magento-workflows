<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Webapi;

use PHPUnit\Framework\TestCase;

/**
 * Contract pins for the F6 metadata routes + the execution-steps route (07).
 * Every route the canvas consumes is GET, read-only under ::view, and bound to
 * the interface the DI preference points at. An endpoint referenced by a plan
 * but built by no route is how contracts drift — this test is the guard.
 */
class MetaRoutesContractTest extends TestCase
{
    /**
     * @return array<int, array{url: string, method: string, service: string, resources: string[]}>
     */
    private function routes(): array
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/webapi.xml');
        $routes = [];
        foreach ($xml->route as $route) {
            $resources = [];
            foreach ($route->resources->resource as $resource) {
                $resources[] = (string) $resource['ref'];
            }
            $routes[] = [
                'url' => (string) $route['url'],
                'method' => (string) $route['method'],
                'service' => (string) $route->service['class'],
                'resources' => $resources,
            ];
        }
        return $routes;
    }

    private function find(string $url, string $method): ?array
    {
        foreach ($this->routes() as $route) {
            if ($route['url'] === $url && $route['method'] === $method) {
                return $route;
            }
        }
        return null;
    }

    /**
     * @return array<int, array{0: string, 1: string}> url => interface
     */
    private function metaRoutes(): array
    {
        return [
            ['/V1/workflows/meta/actions', 'MageOS\Workflows\Api\ActionMetadataProviderInterface'],
            ['/V1/workflows/meta/triggers', 'MageOS\Workflows\Api\TriggerMetadataProviderInterface'],
            ['/V1/workflows/meta/entity-types', 'MageOS\Workflows\Api\EntityTypeMetadataProviderInterface'],
            ['/V1/workflows/meta/secrets', 'MageOS\Workflows\Api\SecretMetadataProviderInterface'],
            ['/V1/workflows/meta/options', 'MageOS\Workflows\Api\OptionSourceProviderInterface'],
        ];
    }

    public function testEveryMetaRouteIsGetUnderViewAcl(): void
    {
        foreach ($this->metaRoutes() as [$url, $service]) {
            $route = $this->find($url, 'GET');
            $this->assertNotNull($route, $url . ' must be a GET route');
            $this->assertSame($service, $route['service'], $url . ' service binding');
            $this->assertSame(['MageOS_Workflows::view'], $route['resources'], $url . ' must be ::view');
        }
    }

    public function testExecutionStepsRouteIsGetUnderViewAcl(): void
    {
        $route = $this->find('/V1/workflow-executions/:executionId/steps', 'GET');
        $this->assertNotNull($route);
        $this->assertSame(
            'MageOS\Workflows\Api\WorkflowExecutionStepsProviderInterface',
            $route['service']
        );
        $this->assertSame(['MageOS_Workflows::view'], $route['resources']);
    }

    public function testMetaRoutesAreReadOnly(): void
    {
        // No meta/* or steps route may ever require a write/manage ACL.
        foreach ($this->routes() as $route) {
            $isMeta = str_starts_with($route['url'], '/V1/workflows/meta/');
            $isSteps = str_ends_with($route['url'], '/steps');
            if ($isMeta || $isSteps) {
                $this->assertSame('GET', $route['method'], $route['url'] . ' must be GET');
                $this->assertSame(['MageOS_Workflows::view'], $route['resources'], $route['url']);
            }
        }
    }
}
