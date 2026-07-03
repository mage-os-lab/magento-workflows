<?php
declare(strict_types=1);

namespace MageOS\Workflows\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use Psr\Log\LoggerInterface;

/**
 * DB-queue resumption sweeper (docs/08-execution-model.md): every minute,
 * wake delay steps whose resume_at has passed by publishing to the
 * mageos.workflow.resume topic. Uses the (status, resume_at) sweeper index.
 *
 * The execution row is claimed atomically (waiting -> pending) so overlapping
 * sweeps or a slow consumer never double-publish the same resumption.
 *
 * Also recovers zombies: steps stuck 'running' with a claim older than 30
 * minutes (consumer died mid-step) are re-claimed and their executions
 * republished to the execute topic — redelivery is safe because execution
 * state is persisted before side effects.
 */
class ResumeSweeper
{
    public const TOPIC_RESUME = 'mageos.workflow.resume';
    public const TOPIC_EXECUTE = 'mageos.workflow.execute';

    private const STEP_TABLE = 'mageos_workflow_execution_step';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';

    private const ZOMBIE_CLAIM_MINUTES = 30;
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $this->resumeDueDelays();
        $this->recoverZombies();
    }

    private function resumeDueDelays(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $now = gmdate('Y-m-d H:i:s');

        $select = $connection->select()
            ->from($stepTable, ['execution_id'])
            ->where('status = ?', WorkflowExecutionStepInterface::STATUS_WAITING)
            ->where('resume_at IS NOT NULL')
            ->where('resume_at <= ?', $now)
            ->limit(self::BATCH_SIZE);

        foreach (array_unique(array_map('intval', $connection->fetchCol($select))) as $executionId) {
            // Atomic claim: only one sweep transitions waiting -> pending
            $claimed = $connection->update(
                $executionTable,
                ['status' => WorkflowExecutionInterface::STATUS_PENDING],
                [
                    'execution_id = ?' => $executionId,
                    'status = ?' => WorkflowExecutionInterface::STATUS_WAITING,
                ]
            );
            if ($claimed !== 1) {
                continue;
            }
            try {
                $this->publisher->publish(self::TOPIC_RESUME, (string) $executionId);
            } catch (\Throwable $e) {
                // Roll the claim back so the next sweep retries
                $connection->update(
                    $executionTable,
                    ['status' => WorkflowExecutionInterface::STATUS_WAITING],
                    [
                        'execution_id = ?' => $executionId,
                        'status = ?' => WorkflowExecutionInterface::STATUS_PENDING,
                    ]
                );
                $this->logger->error(
                    sprintf('Workflow resume sweeper could not publish execution %d: %s', $executionId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }

    private function recoverZombies(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ZOMBIE_CLAIM_MINUTES * 60);

        $select = $connection->select()
            ->from($stepTable, ['step_execution_id', 'execution_id'])
            ->where('status = ?', WorkflowExecutionStepInterface::STATUS_RUNNING)
            ->where('claimed_at IS NOT NULL')
            ->where('claimed_at < ?', $cutoff)
            ->limit(self::BATCH_SIZE);

        foreach ($connection->fetchAll($select) as $row) {
            $stepExecutionId = (int) $row['step_execution_id'];
            $executionId = (int) $row['execution_id'];

            // Re-claim so the next sweep does not republish the same zombie
            $reclaimed = $connection->update(
                $stepTable,
                ['claimed_at' => gmdate('Y-m-d H:i:s')],
                [
                    'step_execution_id = ?' => $stepExecutionId,
                    'status = ?' => WorkflowExecutionStepInterface::STATUS_RUNNING,
                    'claimed_at < ?' => $cutoff,
                ]
            );
            if ($reclaimed !== 1) {
                continue;
            }

            $this->logger->warning(sprintf(
                'Workflow zombie recovery: republishing execution %d (step row %d claimed > %d min ago)',
                $executionId,
                $stepExecutionId,
                self::ZOMBIE_CLAIM_MINUTES
            ));

            try {
                $this->publisher->publish(self::TOPIC_EXECUTE, (string) $executionId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Workflow zombie recovery could not publish execution %d: %s', $executionId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }
}
