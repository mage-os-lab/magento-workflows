<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use Magento\Framework\DataObject;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\DryRun\TraceStep;
use MageOS\Workflows\Model\DryRun\Walker;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubConditionEvaluator;
use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\StubSimulateableAction;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

/**
 * Layer-1 of the dual-engine conformance suite (discovery §5, plan stage 3):
 * routing-equivalence over the published spec fixtures. The walker's edge
 * selection per step type and outcome is asserted against expectations derived
 * from the same fixtures the executor is contract-bound to; both walkers route
 * over {@see Definition::getStepEdges}, so a new step type landing in one but
 * not the other fails here loudly.
 *
 * The full dual-engine diff (fixtures dispatched through the real DB-backed
 * executor in shadow status) is layer-2, authored under Test/Integration and
 * gated on the live-install milestone — see DualEngineConformanceTest.
 */
class ConformanceRoutingTest extends TestCase
{
    /**
     * @return array the fixture definition (unwrapping the export envelope if present)
     */
    private function fixtureDefinition(string $name): array
    {
        $path = dirname(__DIR__, 6) . '/spec/fixtures/' . $name;
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data['definition'] ?? null) ? $data['definition'] : $data;
    }

    /**
     * @param array<string, bool> $conditionResults
     * @param array<string, DataObject> $entities
     * @return array<string, TraceStep> keyed by step key
     */
    private function walkFixture(string $name, array $conditionResults = [], array $entities = []): array
    {
        $def = $this->fixtureDefinition($name);
        $actions = [];
        foreach ($def['steps'] as $step) {
            if (($step['type'] ?? null) === 'action') {
                $actions[$step['action']] = new StubSimulateableAction($step['action']);
            }
        }
        $walker = new Walker(
            new StubConditionEvaluator($conditionResults),
            new StubHydrationProvider($entities),
            new VariableResolver(new SecretsProviderStub()),
            new ActionPool($actions),
            new DelayCalculator(),
            new StubScopeConfig(['general/locale/timezone' => 'UTC'])
        );
        $definition = Definition::fromArray($def);
        $ctx = new ExecutionContext(
            new WorkflowExecutionStub('dry-run', 1, 0),
            ['entity_id' => 1],
            [],
            ['entity_type' => 'sales_order'],
            true
        );
        $result = $walker->walk($definition, $ctx, 'sales_order', (string) $definition->getEntryKey(), 200);
        $byKey = [];
        foreach ($result['steps'] as $step) {
            $byKey[$step->getStepKey()] = $step;
        }
        return $byKey;
    }

    public function testMultiRegionSwitchRoutesToMatchedCase(): void
    {
        $usTree = '{"type":"combine","aggregator":"all","value":"1","conditions":[{"type":"order_attribute","attribute":"shipping_country_id","operator":"==","value":"US"}]}';
        $steps = $this->walkFixture('multi-region-order-routing.json', [$usTree => true]);

        $this->assertSame('case:us', $steps['route']->getEdgeTaken());
        $this->assertTrue(isset($steps['us_note']));
        $this->assertFalse(isset($steps['eu_note']));
        $this->assertFalse(isset($steps['row_note']));
    }

    public function testMultiRegionSwitchFallsToDefaultWhenNoCaseMatches(): void
    {
        // Every case false => default edge (row_note). revalidate_entity is
        // false in this fixture, so no entity registration is needed.
        $steps = $this->walkFixture('multi-region-order-routing.json');
        // StubConditionEvaluator defaults unknown trees to true; force false:
        $usTree = '{"type":"combine","aggregator":"all","value":"1","conditions":[{"type":"order_attribute","attribute":"shipping_country_id","operator":"==","value":"US"}]}';
        $euTree = '{"type":"combine","aggregator":"all","value":"1","conditions":[{"type":"order_attribute","attribute":"shipping_country_id","operator":"()","value":"DE,FR,NL,BE,AT"}]}';
        $steps = $this->walkFixture('multi-region-order-routing.json', [$usTree => false, $euTree => false]);

        $this->assertSame('default', $steps['route']->getEdgeTaken());
        $this->assertTrue(isset($steps['row_note']));
    }

    public function testHighValueFraudLinearRoutingWithPostDelayRevalidation(): void
    {
        // s1 action -> s2 delay -> s3 branch(revalidate) -> s4 webhook.
        $statusTree = '{"type":"combine","aggregator":"all","value":"1","conditions":[{"type":"order_attribute","attribute":"status","operator":"==","value":"pending"}]}';
        $steps = $this->walkFixture(
            'high-value-order-fraud-check.json',
            [$statusTree => true],
            ['sales_order:1' => new DataObject(['entity_id' => 1, 'status' => 'pending'])]
        );

        $this->assertSame('next', $steps['s1']->getEdgeTaken());
        $this->assertNotNull($steps['s2']->getTiming());
        $this->assertSame('on_true', $steps['s3']->getEdgeTaken());
        $this->assertTrue(isset($steps['s4']));
    }

    public function testAbandonedCartWaitFansOutBothOutcomes(): void
    {
        $steps = $this->walkFixture(
            'abandoned-cart-wait-recovery.json',
            [],
            ['sales_order:1' => new DataObject(['entity_id' => 1])]
        );

        // Wait explores both edges: on_event -> thank_you, on_timeout -> still_abandoned.
        $this->assertNull($steps['wait_for_order']->getEdgeTaken());
        $this->assertTrue(isset($steps['thank_you']));
        $this->assertTrue(isset($steps['still_abandoned']));
    }
}
