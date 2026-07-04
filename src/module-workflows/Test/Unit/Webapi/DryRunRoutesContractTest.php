<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Webapi;

use PHPUnit\Framework\TestCase;

/**
 * Contract pins for the dry-run REST routes (03). The route shapes are
 * deliberately non-colliding with GET /V1/workflows/:workflowId: dry-run is
 * POST-only, at a distinct segment depth. A GET on /V1/workflows/dry-run
 * therefore binds :workflowId = 'dry-run' and falls through to
 * getById('dry-run') → NoSuchEntity → 404. This test exists so nobody "fixes"
 * that into a GET route that would collide with the id lookup.
 */
class DryRunRoutesContractTest extends TestCase
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

    public function testDryRunOnDefinitionIsPostOnlyUnderDryRunAcl(): void
    {
        $route = $this->find('/V1/workflows/dry-run', 'POST');
        $this->assertNotNull($route);
        $this->assertSame('MageOS\Workflows\Api\WorkflowDryRunInterface', $route['service']);
        $this->assertSame(['MageOS_Workflows::dry_run'], $route['resources']);
    }

    public function testDryRunOnSavedIsPostOnlyUnderDryRunAcl(): void
    {
        $route = $this->find('/V1/workflows/:workflowId/dry-run', 'POST');
        $this->assertNotNull($route);
        $this->assertSame('MageOS\Workflows\Api\WorkflowDryRunInterface', $route['service']);
        $this->assertSame(['MageOS_Workflows::dry_run'], $route['resources']);
    }

    public function testNoGetDryRunRouteSoItFallsThroughToGetByIdAnd404s(): void
    {
        // If this ever becomes non-null, a GET .../dry-run would stop falling
        // through to getById('dry-run') → 404 and could collide with the id route.
        $this->assertNull($this->find('/V1/workflows/dry-run', 'GET'));

        // The id lookup that swallows the stray GET must still exist.
        $idRoute = $this->find('/V1/workflows/:workflowId', 'GET');
        $this->assertNotNull($idRoute);
        $this->assertSame('MageOS\Workflows\Api\WorkflowRepositoryInterface', $idRoute['service']);
    }

    public function testDryRunAclDoesNotImplyManualRun(): void
    {
        // Both dry-run routes are gated by ::dry_run alone — never ::manual_run.
        foreach (['/V1/workflows/dry-run', '/V1/workflows/:workflowId/dry-run'] as $url) {
            $route = $this->find($url, 'POST');
            $this->assertNotNull($route);
            $this->assertFalse(in_array('MageOS_Workflows::manual_run', $route['resources'], true));
        }
    }
}
