<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\DryRun\DryRunRequest;
use MageOS\Workflows\Model\DryRun\DryRunService;
use MageOS\Workflows\Model\DryRun\FanOutTracePreview;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Test\Unit\Stub\StubStoreManager;
use Psr\Log\NullLogger;
use MageOS\Workflows\Model\DryRun\TraceStepStatus;
use MageOS\Workflows\Model\DryRun\Walker;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Validation\Check\ActionCodesCheck;
use MageOS\Workflows\Model\Validation\Check\ConditionsShapeCheck;
use MageOS\Workflows\Model\Validation\Check\GraphCheck;
use MageOS\Workflows\Model\Validation\Check\StructuralCheck;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubConditionEvaluator;
use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\StubSimulateableAction;
use MageOS\Workflows\Test\Unit\Stub\StubSimulationContextFactory;
use PHPUnit\Framework\TestCase;

/**
 * DryRunService orchestration: the validation subset short-circuit, the
 * missing-entity trace-level error, the root-condition skip, and the happy path
 * that produces a walked trace.
 */
class DryRunServiceTest extends TestCase
{
    /**
     * @param array<string, \MageOS\Workflows\Api\ActionInterface> $actions
     * @param array<string, bool> $conditionResults
     * @param array<string, DataObject> $entities
     */
    private function service(
        array $actions = [],
        array $conditionResults = [],
        array $entities = []
    ): DryRunService {
        $pool = new ActionPool($actions);
        $validator = new WorkflowValidator([
            new StructuralCheck(),
            new GraphCheck(),
            new ActionCodesCheck($pool),
            new ConditionsShapeCheck(),
        ]);
        $walker = new Walker(
            new StubConditionEvaluator($conditionResults),
            new StubHydrationProvider($entities),
            new VariableResolver(new SecretsProviderStub()),
            $pool,
            new DelayCalculator(),
            new StubScopeConfig(['general/locale/timezone' => 'UTC'])
        );
        return new DryRunService(
            $validator,
            new StubConditionEvaluator($conditionResults),
            new StubHydrationProvider($entities),
            $walker,
            new StubSimulationContextFactory(),
            new FanOutTracePreview(
                new RelationPool([]),
                new RelationContext(
                    new RelationPool([]),
                    new StubStoreManager(),
                    new StubScopeConfig([]),
                    new NullLogger()
                ),
                new StubHydrationProvider($entities),
                new DataObjectFactory(),
                new StubScopeConfig([])
            )
        );
    }

    public function testBrokenGraphReturnsFindingsAndNoTrace(): void
    {
        // A cycle reachable from entry is a graph error: no trace is fabricated.
        $definition = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'a.b', 'next' => 's2'],
                's2' => ['type' => 'action', 'action' => 'a.b', 'next' => 's1'],
            ],
        ]);
        $trace = $this->service(['a.b' => new StubSimulateableAction('a.b')])
            ->run(new DryRunRequest((string) $definition, null, 'sales_order', 1));

        $this->assertTrue($trace->hasErrors());
        $this->assertCount(0, $trace->getSteps());
    }

    public function testMissingEntityIsATraceLevelError(): void
    {
        $definition = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'a.b', 'next' => null]],
        ]);
        // No entity registered => entity 999 is missing.
        $trace = $this->service(['a.b' => new StubSimulateableAction('a.b')])
            ->run(new DryRunRequest((string) $definition, null, 'sales_order', 999));

        $this->assertTrue($trace->hasErrors());
        $this->assertSame('DRY_RUN_ENTITY_NOT_FOUND', $trace->getValidation()[0]->getCode());
        $this->assertCount(0, $trace->getSteps());
    }

    public function testRootConditionsNotMatchingYieldsSkippedTrace(): void
    {
        $definition = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'a.b', 'next' => null]],
        ]);
        $trace = $this->service(
            ['a.b' => new StubSimulateableAction('a.b')],
            ['{"root":1}' => false],
            ['sales_order:1' => new DataObject(['entity_id' => 1])]
        )->run(new DryRunRequest((string) $definition, '{"root":1}', 'sales_order', 1));

        $this->assertTrue($trace->isSkipped());
        $this->assertCount(0, $trace->getSteps());
    }

    public function testHappyPathProducesWalkedTrace(): void
    {
        $definition = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'next' => 's2'],
                's2' => ['type' => 'stop'],
            ],
        ]);
        $trace = $this->service(
            ['order.add_comment' => new StubSimulateableAction('order.add_comment')],
            [],
            ['sales_order:1' => new DataObject(['entity_id' => 1])]
        )->run(new DryRunRequest((string) $definition, null, 'sales_order', 1, null, 7, 'My workflow'));

        $this->assertFalse($trace->hasErrors());
        $this->assertFalse($trace->isSkipped());
        $this->assertCount(2, $trace->getSteps());
        $this->assertSame(TraceStepStatus::WOULD_RUN, $trace->getSteps()[0]->getStatus());
        $this->assertSame(7, $trace->getWorkflow()['id']);
    }

    public function testSyntheticPayloadSkipsEntityLookup(): void
    {
        $definition = json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'a.b', 'next' => null]],
        ]);
        // No entity registered, but a synthetic payload bypasses hydration.
        $trace = $this->service(['a.b' => new StubSimulateableAction('a.b')])
            ->run(new DryRunRequest((string) $definition, null, 'sales_order', null, ['entity_id' => 0, 'grand_total' => '500']));

        $this->assertFalse($trace->hasErrors());
        $this->assertCount(1, $trace->getSteps());
    }
}
