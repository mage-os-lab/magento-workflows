<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Integration\Model;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Cron\ReconcileApprovals;
use MageOS\WorkflowsApprovals\Test\Integration\_files\ApprovalTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #26 (docs/20-integration-test-plan.md §6): the overdue/orphan
 * reconciliation backstop (ReconcileApprovals cron) against real MySQL. An open
 * task whose execution reached a terminal status is marked `orphaned`; one whose
 * live execution has walked OFF the gate is marked `expired`; a task whose
 * execution is still parked ON its gate is NEVER touched, whatever the SLA
 * clock. Every transition is guarded on status='open', so a task decided
 * microseconds earlier is never clobbered.
 *
 * DIVERGENCE PINNED (doc vs implementation): docs/20 describes this as an
 * "expiry cron" transitioning "overdue tasks (rewind timestamps)". The shipped
 * ReconcileApprovals (src/module-workflows-approvals/Cron/ReconcileApprovals.php)
 * is state-driven, not due_at/clock-driven — it never reads due_at. The real
 * SLA-timeout expiry runs through the engine's resume path
 * (ResumeConsumer::routeApprovalStep -> expireTask), pinned in
 * ApprovalLifecycleTest::testTimeoutExpiresTaskAndRoutesOnTimeout. This suite
 * therefore exercises the reconciliation semantics the code actually implements
 * and documents that the cron is not the timeout clock.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ApprovalExpiryTest extends TestCase
{
    use ApprovalTestTrait;

    private function reconcile(): void
    {
        $this->om()->get(ReconcileApprovals::class)->execute();
    }

    /**
     * Seed a bare execution row pinned to a stop-step gate snapshot; return its id.
     */
    private function seedGateExecution(): array
    {
        $workflow = $this->createWorkflow([
            'name' => 'reconcile gate ' . uniqid('', true),
            'definition' => $this->approvalDefinition(),
        ]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            4242,
            1
        );
        return [(int) $workflow->getWorkflowId(), (int) $execution->getExecutionId()];
    }

    public function testTerminalExecutionOrphansOpenTask(): void
    {
        [$workflowId, $executionId] = $this->seedGateExecution();
        $this->insertOpenTask($executionId, 'gate', $workflowId);
        // The execution failed by some path other than a decision/timeout.
        $this->setExecutionState($executionId, WorkflowExecutionInterface::STATUS_FAILED, 'gate');

        $this->reconcile();

        $this->assertSame(
            ApprovalInterface::STATUS_ORPHANED,
            $this->taskStatus($executionId),
            'An open task on a terminal execution is orphaned'
        );
    }

    public function testLiveExecutionPastTheGateExpiresOpenTask(): void
    {
        [$workflowId, $executionId] = $this->seedGateExecution();
        $this->insertOpenTask($executionId, 'gate', $workflowId);
        // The execution is live but has demonstrably walked past the gate: the
        // best-effort expireTask in the consumer must have missed it.
        $this->setExecutionState($executionId, WorkflowExecutionInterface::STATUS_RUNNING, 'approved_end');

        $this->reconcile();

        $this->assertSame(
            ApprovalInterface::STATUS_EXPIRED,
            $this->taskStatus($executionId),
            'An open task whose execution walked off the gate is expired'
        );
    }

    public function testTaskStillParkedOnItsGateIsNeverTouched(): void
    {
        [$workflowId, $executionId] = $this->seedGateExecution();
        $this->insertOpenTask($executionId, 'gate', $workflowId);
        // The gate is genuinely parked: waiting, current_step still ON the gate.
        $this->setExecutionState($executionId, WorkflowExecutionInterface::STATUS_WAITING, 'gate');
        // Rewind the SLA clock well past the window — proves the cron is NOT
        // due_at-driven (see the class docblock divergence note).
        $this->rewindTimestamp(
            'mageos_workflow_approval',
            ApprovalInterface::DUE_AT,
            $this->gmPast(86400),
            'execution_id',
            $executionId
        );

        $this->reconcile();

        $this->assertSame(
            ApprovalInterface::STATUS_OPEN,
            $this->taskStatus($executionId),
            'A gate still parked on its own step is left open regardless of the SLA clock'
        );
    }

    public function testDecidedTaskIsNotRevertedByReconciliation(): void
    {
        [$workflowId, $executionId] = $this->seedGateExecution();
        $uuid = $this->insertOpenTask($executionId, 'gate', $workflowId);
        // The task already claimed approved; its execution then reached terminal.
        $this->db()->update(
            $this->table('mageos_workflow_approval'),
            [ApprovalInterface::STATUS => ApprovalInterface::STATUS_APPROVED],
            ['uuid = ?' => $uuid]
        );
        $this->setExecutionState($executionId, WorkflowExecutionInterface::STATUS_COMPLETE, null);

        $this->reconcile();

        $this->assertSame(
            ApprovalInterface::STATUS_APPROVED,
            $this->taskStatus($executionId),
            'The status=open guard keeps a decided task from being orphaned/expired'
        );
    }
}
