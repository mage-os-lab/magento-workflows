<?php
declare(strict_types=1);

namespace MageOS\Workflows\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Daily retention pruning: delete executions whose completed_at is older than
 * mageos_workflows/retention/days (default 90), together with their step rows.
 * Batched so a store with millions of historical executions never locks the
 * tables for long.
 */
class PruneExecutions
{
    public const CONFIG_RETENTION_DAYS = 'mageos_workflows/retention/days';
    public const DEFAULT_RETENTION_DAYS = 90;

    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $days = (int) $this->scopeConfig->getValue(self::CONFIG_RETENTION_DAYS);
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $connection = $this->resourceConnection->getConnection();
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);

        $totalDeleted = 0;
        do {
            $select = $connection->select()
                ->from($executionTable, ['execution_id'])
                ->where('completed_at IS NOT NULL')
                ->where('completed_at < ?', $cutoff)
                ->limit(self::BATCH_SIZE);
            $executionIds = array_map('intval', $connection->fetchCol($select));

            if ($executionIds === []) {
                break;
            }

            $connection->delete($stepTable, ['execution_id IN (?)' => $executionIds]);
            $deleted = $connection->delete($executionTable, ['execution_id IN (?)' => $executionIds]);
            $totalDeleted += $deleted;
        } while (count($executionIds) === self::BATCH_SIZE);

        if ($totalDeleted > 0) {
            $this->logger->info(sprintf(
                'Workflow retention pruning removed %d executions completed before %s (retention %d days)',
                $totalDeleted,
                $cutoff,
                $days
            ));
        }
    }
}
