<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Integration\Model;

use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Test\Integration\_files\ApprovalTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #26 (docs/20-integration-test-plan.md §6): the approval-gate lifecycle
 * end-to-end over real MySQL and the real engine. An `approval` step dispatched
 * through the real Executor (via ExecuteConsumer) parks the execution `waiting`
 * on the gate and creates one `mageos_workflow_approval` row (status `open`);
 * a decision through the real ApprovalService::decide claims the task and the
 * execution atomically, writes {resolution,...} into the parked step BEFORE the
 * resume publish, and the real ResumeConsumer routes on_approved / on_rejected
 * and completes the walk. The timeout path (no decision) expires the task and
 * routes on_timeout. Park notification email is captured via TransportBuilderMock.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ApprovalLifecycleTest extends TestCase
{
    use ApprovalTestTrait;

    public function testApprovalStepParksExecutionAndOpensTask(): void
    {
        [$workflowId, $executionId] = $this->parkApproval();

        $execution = $this->reloadExecution($executionId);
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_WAITING,
            $execution->getStatus(),
            'The approval gate parks the execution waiting'
        );
        $this->assertSame('gate', $execution->getCurrentStep(), 'current_step stays ON the gate (approval spine)');

        $task = $this->taskRow($executionId);
        $this->assertNotNull($task, 'Reaching the gate creates exactly one approval task row');
        $this->assertSame(ApprovalInterface::STATUS_OPEN, (string) $task[ApprovalInterface::STATUS]);
        $this->assertSame('gate', (string) $task[ApprovalInterface::STEP_KEY]);
        $this->assertSame($workflowId, (int) $task[ApprovalInterface::WORKFLOW_ID]);
        $this->assertSame('sales_order', (string) $task[ApprovalInterface::ENTITY_TYPE]);
        $this->assertSame(4242, (int) $task[ApprovalInterface::ENTITY_ID]);
        $this->assertSame('Approve the order', (string) $task[ApprovalInterface::TITLE]);
        $this->assertNotSame('', (string) $task[ApprovalInterface::UUID], 'The task carries its external uuid handle');

        // The gate exposes its task uuid as the step output (task_uuid), so
        // downstream steps can interpolate it.
        $context = json_decode((string) $execution->getContext(), true);
        $this->assertSame(
            (string) $task[ApprovalInterface::UUID],
            $context['steps']['gate']['task_uuid'] ?? null
        );
    }

    public function testApproveResumesWalkDownOnApprovedBranch(): void
    {
        [, $executionId] = $this->parkApproval();
        $uuid = $this->taskUuid($executionId);

        $result = $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_APPROVED, 'looks good', [], 'admin', '1');
        $this->assertSame($uuid, $result->getUuid());
        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $result->getStatus());
        $this->assertSame($executionId, $result->getExecutionId());

        // The decision claims the task and the execution synchronously; the walk
        // continues only when the resume is consumed.
        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $this->taskStatus($executionId));
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_PENDING,
            $this->reloadExecution($executionId)->getStatus(),
            'The decision claims the parked execution waiting -> pending'
        );

        // Result-before-publish: the parked step carries the decision.
        $stepResult = $this->stepResultDecoded($executionId, 'gate');
        $this->assertSame('approved', $stepResult['resolution'] ?? null);
        $this->assertSame('admin:1', $stepResult['decided_by'] ?? null);
        $this->assertSame('looks good', $stepResult['note'] ?? null);

        $this->resume($executionId);

        $this->assertSame(
            WorkflowExecutionInterface::STATUS_COMPLETE,
            $this->reloadExecution($executionId)->getStatus(),
            'The approved walk resumes and completes'
        );
        $statuses = $this->stepStatuses($executionId);
        $this->assertArrayHasKey('approved_end', $statuses, 'on_approved routed to the approved terminal');
        $this->assertArrayNotHasKey('rejected_end', $statuses, 'the rejected branch never ran');
        $this->assertArrayNotHasKey('timeout_end', $statuses, 'the timeout branch never ran');
    }

    public function testRejectResumesWalkDownOnRejectedBranch(): void
    {
        [, $executionId] = $this->parkApproval();
        $uuid = $this->taskUuid($executionId);

        $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_REJECTED, null, [], 'admin', '1');
        $this->assertSame(ApprovalInterface::STATUS_REJECTED, $this->taskStatus($executionId));

        $this->resume($executionId);

        $this->assertSame(
            WorkflowExecutionInterface::STATUS_COMPLETE,
            $this->reloadExecution($executionId)->getStatus(),
            'A rejection is a decided terminal path — the execution ends via on_rejected'
        );
        $statuses = $this->stepStatuses($executionId);
        $this->assertArrayHasKey('rejected_end', $statuses, 'on_rejected routed to the rejected terminal');
        $this->assertArrayNotHasKey('approved_end', $statuses);
    }

    public function testTimeoutExpiresTaskAndRoutesOnTimeout(): void
    {
        [, $executionId] = $this->parkApproval();

        // No decision arrives; the ResumeSweeper's resume publish is delivered.
        // With no decision written into the step result, the consumer routes
        // on_timeout and expires the still-open task through the seam.
        $this->resume($executionId);

        $this->assertSame(
            ApprovalInterface::STATUS_EXPIRED,
            $this->taskStatus($executionId),
            'An undecided gate woken by its timeout marks the task expired'
        );
        $this->assertSame(
            WorkflowExecutionInterface::STATUS_COMPLETE,
            $this->reloadExecution($executionId)->getStatus()
        );
        $this->assertArrayHasKey('timeout_end', $this->stepStatuses($executionId));
    }

    public function testAlreadyDecidedTaskRejectsASecondDecision(): void
    {
        [, $executionId] = $this->parkApproval();
        $uuid = $this->taskUuid($executionId);

        $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_APPROVED, null, [], 'admin', '1');

        $this->expectException(\MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException::class);
        // The task-claim guard is conditioned on status='open'; the loser gets
        // "already decided".
        $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_REJECTED, null, [], 'admin', '2');
    }

    public function testParkNotificationEmailIsCaptured(): void
    {
        [, $executionId] = $this->parkApproval(['notify_emails' => ['approver@example.com']]);

        // The park is a completed side effect: the gate is open and the plugin
        // fired the park notification once.
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $this->taskStatus($executionId));

        /** @var TransportBuilderMock $mock */
        $mock = $this->om()->get(TransportBuilderMock::class);
        $message = $mock->getSentMessage();
        $this->assertNotNull(
            $message,
            'A gate declaring notify_emails must send a park-notification email captured by the mock'
        );
    }

    /**
     * The gate step row stays `waiting` (not complete) after the decision writes
     * its result — the ResumeConsumer is what closes it. Guards the ordering the
     * decision path relies on.
     */
    public function testDecisionLeavesGateStepWaitingUntilResume(): void
    {
        [, $executionId] = $this->parkApproval();
        $uuid = $this->taskUuid($executionId);
        $this->approvalService()->decide($uuid, ApprovalInterface::STATUS_APPROVED, null, [], 'admin', '1');

        $this->assertSame(
            WorkflowExecutionStepInterface::STATUS_WAITING,
            $this->stepStatuses($executionId)['gate'] ?? null,
            'The gate step is closed by the resume consumer, not the decision'
        );
    }
}
