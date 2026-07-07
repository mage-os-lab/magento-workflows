<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Cron;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Cron\PruneExecutions;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Plan #11 (docs/20 §4): retention pruning (docs/08, docs/15). Live and dry-run
 * retention clocks apply independently (a dry-run row older than the short
 * dry-run clock is pruned while a same-age live row survives the longer live
 * clock); in-flight and waiting executions are NEVER pruned regardless of age
 * (completed_at is null); step rows go with their execution.
 *
 * @magentoDbIsolation enabled
 */
class PruneExecutionsTest extends TestCase
{
    use WorkflowEngineTestTrait;

    /**
     * The retention clocks are lowered via config fixture. NOTE: the framework's
     * ConfigFixture annotation handler only honors METHOD-level
     * {@}magentoConfigFixture (unlike DataFixture, which merges class + method),
     * so these MUST live on the method — a class-level copy is silently ignored
     * and the config.xml defaults (90 / 7 days) win, leaving the 40-day live row
     * un-pruned.
     *
     * @magentoConfigFixture mageos_workflows/retention/days 30
     * @magentoConfigFixture mageos_workflows/dry_run/retention_days 7
     */
    public function testRetentionClocksAndInFlightImmunity(): void
    {
        $workflowId = (int) $this->createWorkflow([
            'name' => 'prune fixture',
            'definition' => [
                'schema' => 1,
                'entry' => 's1',
                'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'p'], 'next' => null]],
            ],
        ])->getWorkflowId();

        // Live, completed 40 days ago (> 30-day live clock) => pruned; carries a step.
        $liveOld = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_COMPLETE, 'live', 40);
        $this->insertStep($liveOld);
        // Live, completed 10 days ago (< 30) => kept.
        $liveRecent = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_COMPLETE, 'live', 10);
        // Dry-run, completed 10 days ago (> 7-day dry-run clock) => pruned, even
        // though a same-age LIVE row is kept: the clocks are independent.
        $dryOld = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_COMPLETE, 'dry_run', 10);
        // Dry-run, completed 3 days ago (< 7) => kept.
        $dryRecent = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_COMPLETE, 'dry_run', 3);
        // In-flight (running) and waiting, no completed_at => NEVER pruned.
        $running = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_RUNNING, 'live', null);
        $waiting = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_WAITING, 'live', null);

        $this->om()->get(PruneExecutions::class)->execute();

        $this->assertFalse($this->exists($liveOld), 'Live execution past the live clock is pruned');
        $this->assertTrue($this->exists($liveRecent), 'Live execution within the live clock is kept');
        $this->assertFalse($this->exists($dryOld), 'Dry-run past the dry-run clock is pruned (independent clock)');
        $this->assertTrue($this->exists($dryRecent), 'Dry-run within the dry-run clock is kept');
        $this->assertTrue($this->exists($running), 'In-flight (running) executions are never pruned');
        $this->assertTrue($this->exists($waiting), 'Waiting executions are never pruned');

        $this->assertSame(0, $this->stepCount($liveOld), 'Step rows cascade with their pruned execution');
    }

    private function insertExecution(int $workflowId, string $status, string $mode, ?int $completedAgoDays): int
    {
        $connection = $this->db();
        $data = [
            'uuid' => $this->uuid(),
            'workflow_id' => $workflowId,
            'workflow_version' => 1,
            'definition_snapshot' => '{}',
            'entity_id' => 1,
            'store_id' => 1,
            'status' => $status,
            'mode' => $mode,
            'completed_at' => $completedAgoDays === null ? null : gmdate('Y-m-d H:i:s', time() - $completedAgoDays * 86400),
        ];
        $connection->insert($this->table('mageos_workflow_execution'), $data);
        return (int) $connection->lastInsertId($this->table('mageos_workflow_execution'));
    }

    private function insertStep(int $executionId): void
    {
        $this->db()->insert($this->table('mageos_workflow_execution_step'), [
            'execution_id' => $executionId,
            'step_key' => 's1',
            'status' => 'complete',
        ]);
    }

    private function exists(int $executionId): bool
    {
        $connection = $this->db();
        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->table('mageos_workflow_execution'), 'execution_id')
                ->where('execution_id = ?', $executionId)
        );
    }

    private function stepCount(int $executionId): int
    {
        $connection = $this->db();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->table('mageos_workflow_execution_step'), 'COUNT(*)')
                ->where('execution_id = ?', $executionId)
        );
    }
}
