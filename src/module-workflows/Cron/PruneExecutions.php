<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily retention pruning: delete executions whose completed_at is older than
 * mageos_workflows/retention/days (default 90), together with their step rows.
 * Batched so a store with millions of historical executions never locks the
 * tables for long.
 *
 * Dry-run audit rows (mode='dry_run', 03) are pruned first, on their own much
 * shorter clock (mageos_workflows/dry_run/retention_days, default 7) — they are
 * previews, not history, and carry entity snapshots that should not linger.
 * Whatever survives that pass is still swept by the general retention below.
 */
class PruneExecutions
{
    public const CONFIG_RETENTION_DAYS = 'mageos_workflows/retention/days';
    public const DEFAULT_RETENTION_DAYS = 90;

    public const CONFIG_DRY_RUN_RETENTION_DAYS = 'mageos_workflows/dry_run/retention_days';
    public const DEFAULT_DRY_RUN_RETENTION_DAYS = 7;

    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';
    private const BATCH_TABLE = 'mageos_workflow_batch';

    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $dryRunDays = (int) $this->scopeConfig->getValue(self::CONFIG_DRY_RUN_RETENTION_DAYS);
        if ($dryRunDays <= 0) {
            $dryRunDays = self::DEFAULT_DRY_RUN_RETENTION_DAYS;
        }
        $dryRunDeleted = $this->prune(
            gmdate('Y-m-d H:i:s', time() - $dryRunDays * 86400),
            WorkflowExecutionInterface::MODE_DRY_RUN
        );
        if ($dryRunDeleted > 0) {
            $this->logger->info(sprintf(
                'Workflow retention pruning removed %d dry-run executions (retention %d days)',
                $dryRunDeleted,
                $dryRunDays
            ));
        }

        $days = (int) $this->scopeConfig->getValue(self::CONFIG_RETENTION_DAYS);
        if ($days <= 0) {
            $days = self::DEFAULT_RETENTION_DAYS;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $totalDeleted = $this->prune($cutoff, null);

        if ($totalDeleted > 0) {
            $this->logger->info(sprintf(
                'Workflow retention pruning removed %d executions completed before %s (retention %d days)',
                $totalDeleted,
                $cutoff,
                $days
            ));
        }

        // Batch aggregation (05): flushed batches + their items share the
        // execution-context retention clock (docs/10 PII posture). batch_item
        // rows CASCADE on the batch delete.
        $batchesDeleted = $this->pruneBatches($cutoff);
        if ($batchesDeleted > 0) {
            $this->logger->info(sprintf(
                'Workflow retention pruning removed %d flushed batches before %s',
                $batchesDeleted,
                $cutoff
            ));
        }
    }

    /**
     * Batch-delete flushed batches older than the cutoff (batch_item rows
     * cascade).
     */
    private function pruneBatches(string $cutoff): int
    {
        $connection = $this->resourceConnection->getConnection();
        $batchTable = $this->resourceConnection->getTableName(self::BATCH_TABLE);

        $totalDeleted = 0;
        do {
            $batchIds = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->from($batchTable, ['batch_id'])
                    ->where('status = ?', 'flushed')
                    ->where('flushed_at IS NOT NULL')
                    ->where('flushed_at < ?', $cutoff)
                    ->limit(self::BATCH_SIZE)
            ));
            if ($batchIds === []) {
                break;
            }
            $totalDeleted += $connection->delete($batchTable, ['batch_id IN (?)' => $batchIds]);
        } while (count($batchIds) === self::BATCH_SIZE);

        return $totalDeleted;
    }

    /**
     * Batch-delete completed executions (and their step rows) older than the
     * cutoff, optionally restricted to one mode.
     */
    private function prune(string $cutoff, ?string $mode): int
    {
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
            if ($mode !== null) {
                $select->where('mode = ?', $mode);
            }
            $executionIds = array_map('intval', $connection->fetchCol($select));

            if ($executionIds === []) {
                break;
            }

            $connection->delete($stepTable, ['execution_id IN (?)' => $executionIds]);
            $totalDeleted += $connection->delete($executionTable, ['execution_id IN (?)' => $executionIds]);
        } while (count($executionIds) === self::BATCH_SIZE);

        return $totalDeleted;
    }
}
