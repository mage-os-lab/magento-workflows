<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\ApprovalTaskManager;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\InMemoryConnection;
use PHPUnit\Framework\TestCase;

/**
 * The task-manager seam: idempotent create (including the duplicate-key re-park
 * path) and expire/orphan transitions that touch only 'open' rows.
 */
class ApprovalTaskManagerTest extends TestCase
{
    private InMemoryConnection $connection;
    private ApprovalTaskManager $manager;

    public function setUp(): void
    {
        $this->connection = new InMemoryConnection();
        $this->manager = new ApprovalTaskManager(new FakeResourceConnection($this->connection));
    }

    private function execution(int $executionId = 202): WorkflowExecutionStub
    {
        $execution = new WorkflowExecutionStub('exec-uuid', 55, 1);
        $execution->setExecutionId($executionId);
        $execution->setWorkflowId(7);
        $execution->setContext((string) json_encode([
            'trigger' => [],
            'steps' => [],
            'workflow' => ['entity_type' => 'sales_order'],
        ]));
        return $execution;
    }

    public function testCreateTaskInsertsOpenTaskWithDenormalizedRefs(): void
    {
        $uuid = $this->manager->createTask($this->execution(), 'gate', 'Approve me', 'Do it', '2026-07-08 00:00:00', 'sales_managers');

        $this->assertCount(1, $this->connection->approvals);
        $row = array_values($this->connection->approvals)[0];
        $this->assertSame($uuid, $row[ApprovalInterface::UUID]);
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $row[ApprovalInterface::STATUS]);
        $this->assertSame('sales_order', $row[ApprovalInterface::ENTITY_TYPE]);
        $this->assertSame(55, $row[ApprovalInterface::ENTITY_ID]);
        $this->assertSame(7, $row[ApprovalInterface::WORKFLOW_ID]);
        $this->assertSame('2026-07-08 00:00:00', $row[ApprovalInterface::DUE_AT]);
        $this->assertSame('sales_managers', $row[ApprovalInterface::ASSIGNEE_ROLE]);
    }

    public function testCreateTaskIsIdempotentOnDuplicateKey(): void
    {
        $first = $this->manager->createTask($this->execution(), 'gate', 'T', '', '2026-07-08 00:00:00', null);
        // Redelivered park: same (execution_id, step_key) — the insert hits the
        // unique key and must re-attach to the existing task, not create a second.
        $second = $this->manager->createTask($this->execution(), 'gate', 'T', '', '2026-07-08 00:00:00', null);

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->connection->approvals);
    }

    public function testCreateTaskStoresNullInstructionsWhenEmpty(): void
    {
        $this->manager->createTask($this->execution(), 'gate', 'T', '', '2026-07-08 00:00:00', null);
        $row = array_values($this->connection->approvals)[0];
        $this->assertNull($row[ApprovalInterface::INSTRUCTIONS]);
    }

    public function testExpireTaskMarksOnlyOpenRow(): void
    {
        $openId = $this->connection->seedApproval([
            'execution_id' => 202, 'step_key' => 'gate', 'status' => ApprovalInterface::STATUS_OPEN,
        ]);
        $decidedId = $this->connection->seedApproval([
            'execution_id' => 202, 'step_key' => 'other', 'status' => ApprovalInterface::STATUS_APPROVED,
        ]);

        $this->manager->expireTask(202, 'gate');
        $this->assertSame(ApprovalInterface::STATUS_EXPIRED, $this->connection->approvals[$openId]['status']);

        // A different step already decided must be untouched by this expiry.
        $this->manager->expireTask(202, 'other');
        $this->assertSame(ApprovalInterface::STATUS_APPROVED, $this->connection->approvals[$decidedId]['status']);
    }

    public function testOrphanTasksMarksOnlyOpenRows(): void
    {
        $openId = $this->connection->seedApproval([
            'execution_id' => 202, 'step_key' => 'gate', 'status' => ApprovalInterface::STATUS_OPEN,
        ]);
        $decidedId = $this->connection->seedApproval([
            'execution_id' => 202, 'step_key' => 'g2', 'status' => ApprovalInterface::STATUS_REJECTED,
        ]);

        $this->manager->orphanTasks(202);

        $this->assertSame(ApprovalInterface::STATUS_ORPHANED, $this->connection->approvals[$openId]['status']);
        $this->assertSame(ApprovalInterface::STATUS_REJECTED, $this->connection->approvals[$decidedId]['status']);
    }
}
