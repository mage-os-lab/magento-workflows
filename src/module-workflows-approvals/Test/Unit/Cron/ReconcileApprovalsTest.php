<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Cron;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Cron\ReconcileApprovals;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\InMemoryConnection;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The reconciliation backstop (§4): open tasks orphan when their execution is
 * terminal and expire when their execution is no longer waiting on the gate;
 * a task whose execution still waits on its step, and any already-decided task,
 * are left untouched.
 */
class ReconcileApprovalsTest extends TestCase
{
    public function testReconcilesOrphanedAndExpiredOnly(): void
    {
        $connection = new InMemoryConnection();

        $connection->seedExecution(['execution_id' => 1, 'status' => 'complete', 'current_step' => 'gate']);
        $connection->seedExecution(['execution_id' => 2, 'status' => 'waiting', 'current_step' => 'escalate']);
        $connection->seedExecution(['execution_id' => 3, 'status' => 'waiting', 'current_step' => 'gate']);
        $connection->seedExecution(['execution_id' => 4, 'status' => 'running', 'current_step' => 'x']);

        // Terminal execution -> orphan.
        $a = $connection->seedApproval(['execution_id' => 1, 'step_key' => 'gate', 'status' => ApprovalInterface::STATUS_OPEN]);
        // Live but moved off the gate -> expire (covers the best-effort expireTask miss).
        $b = $connection->seedApproval(['execution_id' => 2, 'step_key' => 'gate', 'status' => ApprovalInterface::STATUS_OPEN]);
        // Still waiting on this gate -> untouched.
        $c = $connection->seedApproval(['execution_id' => 3, 'step_key' => 'gate', 'status' => ApprovalInterface::STATUS_OPEN]);
        // Running past the gate -> expire.
        $d = $connection->seedApproval(['execution_id' => 4, 'step_key' => 'gate', 'status' => ApprovalInterface::STATUS_OPEN]);
        // Already decided -> never reconsidered.
        $e = $connection->seedApproval(['execution_id' => 3, 'step_key' => 'g2', 'status' => ApprovalInterface::STATUS_APPROVED]);

        (new ReconcileApprovals(new FakeResourceConnection($connection), new NullLogger()))->execute();

        $this->assertSame(ApprovalInterface::STATUS_ORPHANED, $connection->approvals[$a]['status']);
        $this->assertSame(ApprovalInterface::STATUS_EXPIRED, $connection->approvals[$b]['status']);
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $connection->approvals[$c]['status']);
        $this->assertSame(ApprovalInterface::STATUS_EXPIRED, $connection->approvals[$d]['status']);
        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $connection->approvals[$e]['status']);
    }

    public function testNoOpenTasksIsANoOp(): void
    {
        $connection = new InMemoryConnection();
        (new ReconcileApprovals(new FakeResourceConnection($connection), new NullLogger()))->execute();
        $this->assertSame([], $connection->updates);
    }
}
