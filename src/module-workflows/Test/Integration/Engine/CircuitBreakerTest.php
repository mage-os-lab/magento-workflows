<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Engine;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Model\Engine\Executor;
use MageOS\Workflows\Test\Integration\_files\ProgrammableAction;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #10 (docs/20 §4): the per-workflow circuit breaker (docs/07). Driving a
 * workflow to the consecutive-failure threshold suspends it (status 3) through
 * the real repository save (status-only, so validation is skipped), and a
 * subsequent dispatch refuses. The threshold is lowered via config fixture so
 * the walk-through is cheap.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class CircuitBreakerTest extends TestCase
{
    use WorkflowEngineTestTrait;

    /**
     * The threshold is lowered via config fixture. NOTE: the framework's
     * ConfigFixture annotation handler only honors METHOD-level
     * {@}magentoConfigFixture (unlike DataFixture, which merges class + method),
     * so it MUST live on the method — a class-level copy is silently ignored and
     * the config.xml default (10) wins, so two failures never trip the breaker.
     *
     * @magentoConfigFixture mageos_workflows/guards/circuit_breaker_threshold 2
     */
    public function testConsecutiveFailuresSuspendTheWorkflowAndRefuseFurtherDispatch(): void
    {
        $action = new ProgrammableAction($this->om()->get(ResourceConnection::class));
        $executor = $this->om()->create(Executor::class, [
            'actionPool' => $this->actionPoolWith(['order.add_comment' => $action]),
        ]);

        $workflow = $this->createWorkflow([
            'name' => 'breaker ' . uniqid('', true),
            'status' => WorkflowInterface::STATUS_ENABLED,
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => [
                    's1' => [
                        'type' => 'action',
                        'action' => 'order.add_comment',
                        'config' => ['comment' => 'boom', '__outcome' => 'fail_terminal'],
                        'next' => null,
                    ],
                ],
            ],
        ]);
        $workflowId = (int) $workflow->getWorkflowId();

        // First failure: below the threshold of 2, workflow stays enabled.
        $this->runFailingExecution($executor, $workflow);
        $this->assertSame(
            WorkflowInterface::STATUS_ENABLED,
            $this->reloadWorkflow($workflowId)->getStatus(),
            'One failure is below the threshold'
        );

        // Second failure reaches the threshold: the breaker trips.
        $this->runFailingExecution($executor, $workflow);
        $this->assertSame(
            WorkflowInterface::STATUS_SUSPENDED,
            $this->reloadWorkflow($workflowId)->getStatus(),
            'Reaching the consecutive-failure threshold suspends the workflow (status 3)'
        );

        // A suspended workflow refuses further dispatch.
        $this->assertNull(
            $this->om()->get(DispatcherInterface::class)->dispatch($workflowId, ['entity_id' => 42, 'store_id' => 1]),
            'A suspended workflow no longer dispatches'
        );
    }

    private function runFailingExecution(Executor $executor, WorkflowInterface $workflow): void
    {
        $execution = $this->seedExecution((int) $workflow->getWorkflowId(), $workflow->getDefinition(), 42, 1);
        $executor->execute((int) $execution->getExecutionId());
        $this->assertSame(
            'failed',
            $this->reloadExecution((int) $execution->getExecutionId())->getStatus()
        );
    }
}
