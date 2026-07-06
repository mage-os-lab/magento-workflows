<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Cron;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use Psr\Log\LoggerInterface;

/**
 * Approval-task reconciliation sweep (docs/discovery/approval-gate.md §4
 * "Orphans"). Rides the same one-minute cadence as the core ResumeSweeper and
 * is the backstop for any open task whose execution has moved on:
 *
 *  - The execution reached a terminal status (complete|cancelled|failed|
 *    skipped) but a task stayed open → mark 'orphaned'. failExecution marks
 *    orphans inline; this catches anything it missed. An open task on a
 *    terminal execution is a bug marker, not a valid state — the count is
 *    logged as such.
 *  - The execution is live and its current_step has moved OFF this gate's
 *    step_key → mark 'expired'. This covers the best-effort expireTask failure
 *    in ResumeConsumer::routeApprovalStep (the parked step row is already
 *    closed there, so an expiry error is logged and swallowed to avoid a
 *    routing loop; the still-open task lands here).
 *  - An open task whose execution's current_step EQUALS its step_key is NEVER
 *    touched, whatever the execution status: the gate is parked (waiting), is
 *    being parked (Executor::walk persists current_step=<gate> with status
 *    'running' BEFORE runApprovalStep parks — state-before-side-effect), or was
 *    just claimed for resume (pending, the consumer routes and expires it).
 *    Expiring in that window would kill a healthy gate mid-park.
 *
 * Each transition is guarded on status='open', so a decision that claimed the
 * task microseconds earlier is never overwritten.
 */
class ReconcileApprovals
{
    private const APPROVAL_TABLE = 'mageos_workflow_approval';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';

    private const BATCH_SIZE = 500;

    private const TERMINAL_STATUSES = [
        WorkflowExecutionInterface::STATUS_COMPLETE,
        WorkflowExecutionInterface::STATUS_CANCELLED,
        WorkflowExecutionInterface::STATUS_FAILED,
        WorkflowExecutionInterface::STATUS_SKIPPED,
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $approvalTable = $this->resourceConnection->getTableName(self::APPROVAL_TABLE);
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);

        $openTasks = $connection->fetchAll(
            $connection->select()
                ->from($approvalTable, ['approval_id', 'execution_id', 'step_key'])
                ->where('status = ?', ApprovalInterface::STATUS_OPEN)
                ->limit(self::BATCH_SIZE)
        );
        if ($openTasks === []) {
            return;
        }

        $executionIds = array_values(array_unique(array_map(
            static fn (array $t): int => (int) $t['execution_id'],
            $openTasks
        )));
        $executions = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($executionTable, ['execution_id', 'status', 'current_step'])
                ->where('execution_id IN (?)', $executionIds)
        ) as $row) {
            $executions[(int) $row['execution_id']] = $row;
        }

        $orphaned = 0;
        $expired = 0;
        foreach ($openTasks as $task) {
            $executionId = (int) $task['execution_id'];
            $execution = $executions[$executionId] ?? null;
            if ($execution === null) {
                // The execution row is gone; the FK cascade removes the task on
                // prune, so nothing to do here.
                continue;
            }

            $status = (string) $execution['status'];
            if (in_array($status, self::TERMINAL_STATUSES, true)) {
                $orphaned += $this->transition(
                    $connection,
                    $approvalTable,
                    (int) $task['approval_id'],
                    ApprovalInterface::STATUS_ORPHANED
                );
                continue;
            }

            // current_step still on the gate = parked, being parked, or being
            // resumed — always leave it alone. Expire only once the execution
            // demonstrably walked past this gate.
            $onGate = (string) ($execution['current_step'] ?? '') === (string) $task['step_key'];
            if (!$onGate) {
                $expired += $this->transition(
                    $connection,
                    $approvalTable,
                    (int) $task['approval_id'],
                    ApprovalInterface::STATUS_EXPIRED
                );
            }
        }

        if ($orphaned > 0 || $expired > 0) {
            $this->logger->warning('approval_reconciliation', [
                'orphaned' => $orphaned,
                'expired' => $expired,
            ]);
        }
    }

    private function transition(object $connection, string $table, int $approvalId, string $status): int
    {
        return (int) $connection->update(
            $table,
            [ApprovalInterface::STATUS => $status],
            ['approval_id = ?' => $approvalId, 'status = ?' => ApprovalInterface::STATUS_OPEN]
        );
    }
}
