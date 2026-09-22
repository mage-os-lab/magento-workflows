<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use Magento\Framework\DataObject;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\DryRun\TraceStep;
use MageOS\Workflows\Model\DryRun\TraceStepStatus;
use MageOS\Workflows\Model\DryRun\Walker;
use MageOS\Workflows\Model\Engine\DelayCalculator;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Model\Secrets\RedactingSecretsProvider;
use MageOS\Workflows\Model\Variable\VariableResolver;
use MageOS\Workflows\Test\Unit\Stub\SecretsProviderStub;
use MageOS\Workflows\Test\Unit\Stub\StubAction;
use MageOS\Workflows\Test\Unit\Stub\StubConditionEvaluator;
use MageOS\Workflows\Test\Unit\Stub\StubHydrationProvider;
use MageOS\Workflows\Test\Unit\Stub\StubScopeConfig;
use MageOS\Workflows\Test\Unit\Stub\StubSimulateableAction;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven routing-equivalence tests for the dry-run walker — the primary
 * layer-1 divergence control (discovery §5): for each step type × edge outcome,
 * the walker's edge selection must match what production routing would take.
 * Both the walker and the executor read the same {@see Definition::getStepEdges}
 * helper, so this pins the selection logic that sits on top of it.
 */
class WalkerTest extends TestCase
{
    /**
     * @param array $steps
     * @param array<string, \MageOS\Workflows\Api\ActionInterface> $actions
     * @param array<string, bool> $conditionResults
     * @param string[] $throwFor
     * @param array<string, DataObject> $entities
     * @return array{steps: TraceStep[], truncated: bool}
     */
    private function walk(
        array $steps,
        string $entry,
        array $actions = [],
        array $conditionResults = [],
        array $throwFor = [],
        array $entities = [],
        int $entityId = 1,
        ?VariableResolver $resolver = null,
        ?DelayCalculator $delayCalculator = null,
        int $maxDistinctSteps = 200
    ): array {
        $walker = new Walker(
            new StubConditionEvaluator($conditionResults, $throwFor),
            new StubHydrationProvider($entities),
            $resolver ?? new VariableResolver(new SecretsProviderStub()),
            new ActionPool($actions),
            $delayCalculator ?? new DelayCalculator(),
            new StubScopeConfig(['general/locale/timezone' => 'UTC'])
        );
        $definition = Definition::fromArray(['schema' => 3, 'entry' => $entry, 'steps' => $steps]);
        $ctx = new ExecutionContext(
            new WorkflowExecutionStub('dry-run', $entityId, 0),
            ['entity_id' => $entityId],
            [],
            ['entity_type' => 'sales_order'],
            true
        );
        return $walker->walk($definition, $ctx, 'sales_order', $entry, $maxDistinctSteps);
    }

    /**
     * @param TraceStep[] $steps
     * @return array<string, TraceStep>
     */
    private function byKey(array $steps): array
    {
        $out = [];
        foreach ($steps as $step) {
            $out[$step->getStepKey()] = $step;
        }
        return $out;
    }

    public function testLinearActionThenStop(): void
    {
        $result = $this->walk(
            [
                's1' => ['type' => 'action', 'action' => 'a.b', 'next' => 's2'],
                's2' => ['type' => 'stop'],
            ],
            's1',
            ['a.b' => new StubSimulateableAction('a.b')]
        );

        $this->assertCount(2, $result['steps']);
        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_RUN, $byKey['s1']->getStatus());
        $this->assertSame('next', $byKey['s1']->getEdgeTaken());
        $this->assertSame(['p1'], $byKey['s1']->getPathIds());
        $this->assertSame(TraceStepStatus::WOULD_RUN, $byKey['s2']->getStatus());
        $this->assertSame('would run a.b', $byKey['s1']->getWould());
    }

    public function testActionSimulateFailureDoesNotStopWalkAndFlagsDownstream(): void
    {
        $result = $this->walk(
            [
                's1' => ['type' => 'action', 'action' => 'bad', 'next' => 's2'],
                's2' => ['type' => 'action', 'action' => 'good', 'next' => null],
            ],
            's1',
            [
                'bad' => new StubSimulateableAction('bad', ActionResult::failure('boom')),
                'good' => new StubSimulateableAction('good'),
            ]
        );

        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_FAIL, $byKey['s1']->getStatus());
        // The walk continued so every problem surfaces; s2 is production-unreachable.
        $this->assertSame(TraceStepStatus::PRODUCTION_STOPS_HERE, $byKey['s2']->getStatus());
    }

    public function testActionSimulateSkippedMapsToSkipped(): void
    {
        $result = $this->walk(
            ['s1' => ['type' => 'action', 'action' => 'a.b', 'next' => null]],
            's1',
            ['a.b' => new StubSimulateableAction('a.b', ActionResult::skipped('nothing to do'))]
        );
        $this->assertSame(TraceStepStatus::SKIPPED, $result['steps'][0]->getStatus());
    }

    public function testNonSimulateableActionRunsWithBadge(): void
    {
        $result = $this->walk(
            ['s1' => ['type' => 'action', 'action' => 'legacy', 'next' => null]],
            's1',
            ['legacy' => new StubAction('legacy')]
        );
        $this->assertSame(TraceStepStatus::WOULD_RUN, $result['steps'][0]->getStatus());
        $this->assertStringContainsString('does not support simulation', $result['steps'][0]->getNotes()[0]);
    }

    public function testUnknownActionCodeFailsAndStopsProduction(): void
    {
        $result = $this->walk(
            [
                's1' => ['type' => 'action', 'action' => 'missing', 'next' => 's2'],
                's2' => ['type' => 'stop'],
            ],
            's1'
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_FAIL, $byKey['s1']->getStatus());
        $this->assertSame(TraceStepStatus::PRODUCTION_STOPS_HERE, $byKey['s2']->getStatus());
    }

    public function testBranchTrueTakesOnTrueEdge(): void
    {
        $result = $this->walk(
            [
                'b' => ['type' => 'branch', 'conditions_serialized' => '{"t":1}', 'revalidate_entity' => false, 'on_true' => 'y', 'on_false' => 'n'],
                'y' => ['type' => 'stop'],
                'n' => ['type' => 'stop'],
            ],
            'b',
            [],
            ['{"t":1}' => true]
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame('on_true', $byKey['b']->getEdgeTaken());
        $this->assertTrue(isset($byKey['y']));
        $this->assertFalse(isset($byKey['n']));
        $this->assertSame(true, $byKey['b']->getCondition()['result']);
    }

    public function testBranchFalseTakesOnFalseEdge(): void
    {
        $result = $this->walk(
            [
                'b' => ['type' => 'branch', 'conditions_serialized' => '{"t":1}', 'revalidate_entity' => false, 'on_true' => 'y', 'on_false' => 'n'],
                'y' => ['type' => 'stop'],
                'n' => ['type' => 'stop'],
            ],
            'b',
            [],
            ['{"t":1}' => false]
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame('on_false', $byKey['b']->getEdgeTaken());
        $this->assertFalse(isset($byKey['y']));
        $this->assertTrue(isset($byKey['n']));
    }

    public function testBranchEmptyConditionsTakesOnTrue(): void
    {
        $result = $this->walk(
            [
                'b' => ['type' => 'branch', 'conditions_serialized' => '', 'on_true' => 'y', 'on_false' => null],
                'y' => ['type' => 'stop'],
            ],
            'b'
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame('on_true', $byKey['b']->getEdgeTaken());
        $this->assertNull($byKey['b']->getCondition());
    }

    public function testBranchUnevaluableExploresBothEdges(): void
    {
        $result = $this->walk(
            [
                'b' => ['type' => 'branch', 'conditions_serialized' => '{"bad":1}', 'on_true' => 'y', 'on_false' => 'n'],
                'y' => ['type' => 'stop'],
                'n' => ['type' => 'stop'],
            ],
            'b',
            [],
            [],
            ['{"bad":1}']
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_FAIL, $byKey['b']->getStatus());
        $this->assertNull($byKey['b']->getEdgeTaken());
        $this->assertTrue(isset($byKey['y']));
        $this->assertTrue(isset($byKey['n']));
    }

    public function testBranchRevalidateMissingEntityExploresBothEdges(): void
    {
        // revalidate_entity true + a vanished entity: production silently follows
        // the false edge; dry-run flags it and explores both.
        $result = $this->walk(
            [
                'b' => [
                    'type' => 'branch',
                    'conditions_serialized' => '{"t":1}',
                    'revalidate_entity' => true,
                    'on_true' => 'y',
                    'on_false' => 'n',
                ],
                'y' => ['type' => 'stop'],
                'n' => ['type' => 'stop'],
            ],
            'b',
            [],
            ['{"t":1}' => true],
            [],
            [] // no entities registered => vanished
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_FAIL, $byKey['b']->getStatus());
        $this->assertTrue(isset($byKey['y']));
        $this->assertTrue(isset($byKey['n']));
    }

    public function testSwitchMatchedCaseTakesCaseEdge(): void
    {
        $result = $this->walk(
            [
                'sw' => [
                    'type' => 'switch',
                    'revalidate_entity' => false,
                    'cases' => [
                        ['key' => 'us', 'conditions_serialized' => '{"us":1}', 'next' => 'us_note'],
                        ['key' => 'eu', 'conditions_serialized' => '{"eu":1}', 'next' => 'eu_note'],
                    ],
                    'default' => 'row_note',
                ],
                'us_note' => ['type' => 'stop'],
                'eu_note' => ['type' => 'stop'],
                'row_note' => ['type' => 'stop'],
            ],
            'sw',
            [],
            ['{"us":1}' => false, '{"eu":1}' => true]
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame('case:eu', $byKey['sw']->getEdgeTaken());
        $this->assertTrue(isset($byKey['eu_note']));
        $this->assertFalse(isset($byKey['us_note']));
        $this->assertFalse(isset($byKey['row_note']));
    }

    public function testSwitchNoMatchTakesDefault(): void
    {
        $result = $this->walk(
            [
                'sw' => [
                    'type' => 'switch',
                    'revalidate_entity' => false,
                    'cases' => [
                        ['key' => 'us', 'conditions_serialized' => '{"us":1}', 'next' => 'us_note'],
                    ],
                    'default' => 'row_note',
                ],
                'us_note' => ['type' => 'stop'],
                'row_note' => ['type' => 'stop'],
            ],
            'sw',
            [],
            ['{"us":1}' => false]
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame('default', $byKey['sw']->getEdgeTaken());
        $this->assertTrue(isset($byKey['row_note']));
    }

    public function testSwitchUnevaluableCaseExploresEveryEdge(): void
    {
        $result = $this->walk(
            [
                'sw' => [
                    'type' => 'switch',
                    'revalidate_entity' => false,
                    'cases' => [
                        ['key' => 'us', 'conditions_serialized' => '{"bad":1}', 'next' => 'us_note'],
                        ['key' => 'eu', 'conditions_serialized' => '{"eu":1}', 'next' => 'eu_note'],
                    ],
                    'default' => 'row_note',
                ],
                'us_note' => ['type' => 'stop'],
                'eu_note' => ['type' => 'stop'],
                'row_note' => ['type' => 'stop'],
            ],
            'sw',
            [],
            [],
            ['{"bad":1}']
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_FAIL, $byKey['sw']->getStatus());
        $this->assertTrue(isset($byKey['us_note']));
        $this->assertTrue(isset($byKey['eu_note']));
        $this->assertTrue(isset($byKey['row_note']));
    }

    public function testWaitFansOutToBothEdges(): void
    {
        $result = $this->walk(
            [
                'w' => [
                    'type' => 'wait',
                    'config' => ['event' => 'sales.order.created', 'timeout' => 'PT4H'],
                    'on_event' => 'e',
                    'on_timeout' => 't',
                ],
                'e' => ['type' => 'stop'],
                't' => ['type' => 'stop'],
            ],
            'w'
        );
        $byKey = $this->byKey($result['steps']);
        $this->assertSame(TraceStepStatus::WOULD_RUN, $byKey['w']->getStatus());
        $this->assertNull($byKey['w']->getEdgeTaken());
        $this->assertTrue(isset($byKey['e']));
        $this->assertTrue(isset($byKey['t']));
        $this->assertNotNull($byKey['w']->getTiming());
    }

    public function testWaitSharedTailRenderedOnceCarryingBothPathIds(): void
    {
        // on_event and on_timeout reconverge on 'tail'; the tail is emitted once
        // and accumulates both path ids (rejoin dedupe, discovery §4).
        $result = $this->walk(
            [
                'w' => [
                    'type' => 'wait',
                    'config' => ['event' => 'e.x', 'timeout' => 'PT1H'],
                    'on_event' => 'tail',
                    'on_timeout' => 'tail',
                ],
                'tail' => ['type' => 'action', 'action' => 'a.b', 'next' => null],
            ],
            'w',
            ['a.b' => new StubSimulateableAction('a.b')]
        );
        // Exactly two distinct steps: the wait and the single shared tail.
        $this->assertCount(2, $result['steps']);
        $byKey = $this->byKey($result['steps']);
        $this->assertCount(2, $byKey['tail']->getPathIds());
    }

    public function testDelayAnnotatesTimingAndDelegatesToDelayCalculator(): void
    {
        $spy = new class extends DelayCalculator {
            public int $calls = 0;
            public function computeResumeAt(\DateTimeImmutable $nowUtc, array $config, string $timezone, int $maxDays): array
            {
                $this->calls++;
                return parent::computeResumeAt($nowUtc, $config, $timezone, $maxDays);
            }
        };
        $result = $this->walk(
            [
                'd' => ['type' => 'delay', 'config' => ['duration' => 'PT1H'], 'next' => null],
            ],
            'd',
            [],
            [],
            [],
            [],
            1,
            null,
            $spy
        );
        $this->assertSame(1, $spy->calls);
        $timing = $result['steps'][0]->getTiming();
        $this->assertNotNull($timing);
        $this->assertSame('UTC', $timing['timezone']);
        $this->assertFalse($timing['clamped']);
    }

    public function testSecretsAreRedactedInInterpolatedConfig(): void
    {
        $action = new StubSimulateableAction('notify.webhook');
        $resolver = new VariableResolver(new RedactingSecretsProvider(new SecretsProviderStub(['fraud_hmac' => 'super-secret'])));
        $result = $this->walk(
            [
                's1' => [
                    'type' => 'action',
                    'action' => 'notify.webhook',
                    'config' => ['url' => 'https://hooks/{{ secrets.fraud_hmac }}'],
                    'next' => null,
                ],
            ],
            's1',
            ['notify.webhook' => $action],
            [],
            [],
            [],
            1,
            $resolver
        );
        $config = $result['steps'][0]->getConfig();
        $this->assertSame('https://hooks/***fraud_hmac***', $config['url']);
        // The redaction reached the action too — never the real value.
        $this->assertSame('https://hooks/***fraud_hmac***', $action->lastConfig['url']);
    }
}
