<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Engine\Executor;
use MageOS\Workflows\Test\Integration\_files\ProgrammableAction;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #7 (docs/20 §4): the end-to-end executor spine (docs/08), one scenario
 * per documented walk guarantee, driven over the real DB. A ProgrammableAction
 * is injected under the real `order.add_comment` code via an ActionPool
 * override (no test-scoped di.xml is permitted), so the executor's
 * persist-before-side-effect discipline and its terminal/retryable/skip
 * semantics are observed directly. Branch/switch route by real ConditionEvaluator
 * evaluation; the pinned definition_snapshot is immune to mid-flight edits.
 *
 * @magentoDbIsolation enabled
 */
class ExecutorWalkTest extends TestCase
{
    use WorkflowEngineTestTrait;

    private ProgrammableAction $action;

    protected function setUp(): void
    {
        $this->action = new ProgrammableAction($this->om()->get(ResourceConnection::class));
    }

    private function executor(): Executor
    {
        return $this->om()->create(Executor::class, [
            'actionPool' => $this->actionPoolWith(['order.add_comment' => $this->action]),
        ]);
    }

    public function testLinearWalkExecutesStepsInOrderWithRowPersistedBeforeEachSideEffect(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => $this->actionStep('success', 's2'),
                's2' => $this->actionStep('success', 's3'),
                's3' => $this->actionStep('success', null),
            ],
        ];
        $workflow = $this->createWorkflow(['name' => 'linear', 'definition' => $definition]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            1,
            1
        );

        $this->executor()->execute((int) $execution->getExecutionId());

        $this->assertSame(['s1', 's2', 's3'], $this->action->ranSteps(), 'Actions run in linear graph order');
        foreach ($this->action->calls as $call) {
            $this->assertSame(
                'running',
                $call['observed_status'],
                'The step row is persisted (status running) BEFORE the side effect runs'
            );
        }
        $this->assertSame('complete', $this->reloadExecution((int) $execution->getExecutionId())->getStatus());
        $this->assertSame(
            ['s1' => 'complete', 's2' => 'complete', 's3' => 'complete'],
            $this->stepStatuses((int) $execution->getExecutionId())
        );
    }

    public function testRootConditionsFalseSkipsWithoutSideEffects(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => $this->actionStep('success', null)],
        ];
        // Real serialized condition format: the `type` is the FQCN of the
        // condition/combine class (see Order\Combine::getNewChildSelectOptions
        // and the spec envelopes). A made-up short alias like "order_attribute"
        // does not resolve; core Combine::loadArray catches the factory failure
        // and drops the leaf, leaving an empty combine that always validates
        // true — so a false root condition would never skip.
        $conditions = json_encode([
            'type' => \MageOS\WorkflowsSales\Model\Rule\Condition\Order\Combine::class,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => [
                [
                    'type' => \MageOS\WorkflowsSales\Model\Rule\Condition\Order\Attribute::class,
                    'attribute' => 'grand_total',
                    'operator' => '>=',
                    'value' => '500',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $workflow = $this->createWorkflow([
            'name' => 'root false',
            'definition' => $definition,
            'conditions' => $conditions,
        ]);
        // Trigger snapshot carries grand_total below the threshold: phase-1
        // (no revalidate) evaluates the snapshot => false.
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            999,
            1,
            ['entity_id' => 999, 'grand_total' => 100]
        );

        $this->executor()->execute((int) $execution->getExecutionId());

        $this->assertSame(
            WorkflowExecutionInterface::STATUS_SKIPPED,
            $this->reloadExecution((int) $execution->getExecutionId())->getStatus()
        );
        $this->assertSame([], $this->action->calls, 'No action ran on a skipped execution');
        $this->assertSame([], $this->stepRows((int) $execution->getExecutionId()), 'No step rows on a skip');
    }

    public function testTerminalFailureFailsExecutionAndHaltsLaterSteps(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => $this->actionStep('success', 's2'),
                's2' => $this->actionStep('fail_terminal', 's3'),
                's3' => $this->actionStep('success', null),
            ],
        ];
        $workflow = $this->createWorkflow(['name' => 'terminal', 'definition' => $definition]);
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), 1, 1);

        $this->executor()->execute((int) $execution->getExecutionId());

        $this->assertSame(['s1', 's2'], $this->action->ranSteps(), 's3 is never reached past a terminal failure');
        $statuses = $this->stepStatuses((int) $execution->getExecutionId());
        $this->assertSame('failed', $statuses['s2']);
        $this->assertArrayNotHasKey('s3', $statuses);
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_FAILED,
            $this->reloadExecution((int) $execution->getExecutionId())->getStatus()
        );
    }

    public function testRetryableFailureRethrowsAndParksTheStepPending(): void
    {
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => $this->actionStep('fail_retryable', null)],
        ];
        $workflow = $this->createWorkflow(['name' => 'retryable', 'definition' => $definition]);
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), 1, 1);
        $id = (int) $execution->getExecutionId();

        $threw = false;
        try {
            $this->executor()->execute($id);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'A retryable failure rethrows so the queue redelivers');

        $statuses = $this->stepStatuses($id);
        $this->assertSame('pending', $statuses['s1'], 'The step is parked pending for redelivery');
        // State persisted before the throw: the execution stays running (not terminal).
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_RUNNING,
            $this->reloadExecution($id)->getStatus()
        );
    }

    public function testBranchRoutesByRealConditionEvaluationAgainstTheSnapshot(): void
    {
        $trueTree = '{"type":"combine","aggregator":"all","value":"1","conditions":'
            . '[{"type":"order_attribute","attribute":"grand_total","operator":">=","value":"500"}]}';
        $definition = [
            'schema' => 1,
            'entry' => 'b1',
            'steps' => [
                'b1' => [
                    'type' => 'branch',
                    'conditions_serialized' => $trueTree,
                    'revalidate_entity' => false,
                    'on_true' => 't1',
                    'on_false' => 'f1',
                ],
                't1' => $this->actionStep('success', null),
                'f1' => $this->actionStep('success', null),
            ],
        ];
        $workflow = $this->createWorkflow(['name' => 'branch', 'definition' => $definition]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            123,
            1,
            ['entity_id' => 123, 'grand_total' => 1000]
        );

        $this->executor()->execute((int) $execution->getExecutionId());

        $this->assertSame(['t1'], $this->action->ranSteps(), 'grand_total 1000 >= 500 routes on_true');
        $this->assertArrayHasKey('b1', $this->stepStatuses((int) $execution->getExecutionId()));
    }

    public function testSwitchRoutesFirstMatchAgainstTheSnapshot(): void
    {
        $usTree = '{"type":"combine","aggregator":"all","value":"1","conditions":'
            . '[{"type":"order_attribute","attribute":"shipping_country_id","operator":"==","value":"US"}]}';
        $definition = [
            'schema' => 3,
            'entry' => 'route',
            'steps' => [
                'route' => [
                    'type' => 'switch',
                    'revalidate_entity' => false,
                    'cases' => [
                        ['key' => 'us', 'conditions_serialized' => $usTree, 'next' => 'us_note'],
                    ],
                    'default' => 'row_note',
                ],
                'us_note' => $this->actionStep('success', null),
                'row_note' => $this->actionStep('success', null),
            ],
        ];
        $workflow = $this->createWorkflow(['name' => 'switch', 'definition' => $definition]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            124,
            1,
            ['entity_id' => 124, 'shipping_country_id' => 'US']
        );

        $this->executor()->execute((int) $execution->getExecutionId());
        $this->assertSame(['us_note'], $this->action->ranSteps(), 'The matched case wins first-match');
    }

    /**
     * revalidate_entity=true re-hydrates the entity fresh from the DB; a
     * condition on the real order's own id proves the walk evaluated the
     * hydrated entity, not the snapshot.
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testBranchRevalidateEntityReHydratesFromDatabase(): void
    {
        $order = $this->seededOrder();
        $orderId = (int) $order->getId();
        $idTree = '{"type":"combine","aggregator":"all","value":"1","conditions":'
            . '[{"type":"order_attribute","attribute":"entity_id","operator":"==","value":"' . $orderId . '"}]}';
        $definition = [
            'schema' => 1,
            'entry' => 'b1',
            'steps' => [
                'b1' => [
                    'type' => 'branch',
                    'conditions_serialized' => $idTree,
                    'revalidate_entity' => true,
                    'on_true' => 't1',
                    'on_false' => null,
                ],
                't1' => $this->actionStep('success', null),
            ],
        ];
        $workflow = $this->createWorkflow(['name' => 'branch revalidate', 'definition' => $definition]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            $orderId,
            (int) $order->getStoreId(),
            ['entity_id' => $orderId]
        );

        $this->executor()->execute((int) $execution->getExecutionId());
        $this->assertSame(['t1'], $this->action->ranSteps(), 'The re-hydrated order matches its own id');
    }

    public function testIterationCapHaltsACyclicDefinition(): void
    {
        // Cyclic graph the GraphCheck would reject: stage it past validation.
        $definition = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => $this->actionStep('success', 's2'),
                's2' => $this->actionStep('success', 's1'),
            ],
        ];
        $workflowId = $this->insertWorkflowRow(['name' => 'cyclic', 'definition' => $definition]);
        $execution = $this->seedExecution(
            $workflowId,
            json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            1,
            1
        );

        $this->executor()->execute((int) $execution->getExecutionId());

        $this->assertSame(
            WorkflowExecutionInterface::STATUS_FAILED,
            $this->reloadExecution((int) $execution->getExecutionId())->getStatus(),
            'A cyclic definition trips the hard iteration cap and fails the execution'
        );
        $this->assertGreaterThan(500, count($this->action->calls), 'The walk iterated up to the cap before failing');
    }

    public function testDefinitionSnapshotIsImmuneToMidFlightWorkflowEdits(): void
    {
        $original = [
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => $this->actionStep('success', null)],
        ];
        $workflow = $this->createWorkflow(['name' => 'snapshot immune', 'definition' => $original]);
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), 1, 1);

        // Mutate the live workflow to an entirely different graph mid-flight.
        $edit = $this->reloadWorkflow((int) $workflow->getWorkflowId());
        $edit->setDefinition(json_encode([
            'schema' => 1,
            'entry' => 'x1',
            'steps' => ['x1' => $this->actionStep('success', null)],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->om()->get(\MageOS\Workflows\Api\WorkflowRepositoryInterface::class)->save($edit);

        $this->executor()->execute((int) $execution->getExecutionId());

        // The pinned snapshot (s1) executed, NOT the live edit (x1).
        $this->assertSame(['s1'], $this->action->ranSteps());
        $this->assertArrayHasKey('s1', $this->stepStatuses((int) $execution->getExecutionId()));
        $this->assertArrayNotHasKey('x1', $this->stepStatuses((int) $execution->getExecutionId()));
    }

    /**
     * @return array an action step mapped to the injected ProgrammableAction
     */
    private function actionStep(string $outcome, ?string $next): array
    {
        return [
            'type' => 'action',
            'action' => 'order.add_comment',
            'config' => ['comment' => 'walk', '__outcome' => $outcome],
            'next' => $next,
        ];
    }
}
