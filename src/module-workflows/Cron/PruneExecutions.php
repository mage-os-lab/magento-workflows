<?php
declare(strict_types=1);

namespace MageOS\Workflows\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Model\Engine\Dispatcher;
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
 *
 * Debounce slots (mageos_workflow_debounce) are swept here too: the
 * dispatcher's insert IS the debounce check, so every guarded dispatch leaves
 * a permanent row that nothing else deletes. A slot only guards its own time
 * bucket (intdiv(now, window)), so anything older than one full window can
 * never match again — pruned on a generous multiple of the configured window,
 * never a retention-days clock.
 *
 * Send-log claims (mageos_workflow_send_log) are swept on their own clock for
 * the same "nothing else deletes them" reason: one row per unrecallable send,
 * so they grow exactly as fast as the store emails. Their horizon is NOT the
 * debounce horizon, though — a claim must outlive every redelivery that could
 * still reach its step (queue retries, a stranded execution recovered days
 * later), because deleting it early re-arms the double send it exists to
 * prevent. Hence a days-scale clock of its own
 * (mageos_workflows/retention/send_log_days, default 30) with a hard 7-day
 * floor, independent of — and normally much shorter than — the 90-day
 * execution retention.
 */
class PruneExecutions
{
    public const CONFIG_RETENTION_DAYS = 'mageos_workflows/retention/days';
    public const DEFAULT_RETENTION_DAYS = 90;

    public const CONFIG_DRY_RUN_RETENTION_DAYS = 'mageos_workflows/dry_run/retention_days';
    public const DEFAULT_DRY_RUN_RETENTION_DAYS = 7;

    public const CONFIG_SEND_LOG_RETENTION_DAYS = 'mageos_workflows/retention/send_log_days';
    public const DEFAULT_SEND_LOG_RETENTION_DAYS = 30;

    /**
     * A send claim younger than this is never pruned, whatever the config says:
     * dropping a claim while a redelivery could still arrive re-arms the
     * double send the claim exists to prevent (docs/15 retention).
     */
    public const MIN_SEND_LOG_RETENTION_DAYS = 7;

    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';
    private const BATCH_TABLE = 'mageos_workflow_batch';
    private const DEBOUNCE_TABLE = 'mageos_workflow_debounce';
    private const SEND_LOG_TABLE = 'mageos_workflow_send_log';

    private const BATCH_SIZE = 1000;

    /**
     * Debounce slots expire after one window; keep 2x (floor: one hour) so a
     * mid-flight window-config change or clock skew never revives a dispatch
     * the merchant expected debounced.
     */
    private const DEBOUNCE_SAFETY_MULTIPLIER = 2;
    private const DEBOUNCE_MIN_KEEP_SECONDS = 3600;

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

        $debounceDeleted = $this->pruneDebounceSlots();
        if ($debounceDeleted > 0) {
            $this->logger->info(sprintf(
                'Workflow retention pruning removed %d expired debounce slots',
                $debounceDeleted
            ));
        }

        $sendLogDeleted = $this->pruneSendLog();
        if ($sendLogDeleted > 0) {
            $this->logger->info(sprintf(
                'Workflow retention pruning removed %d expired send-log claims',
                $sendLogDeleted
            ));
        }
    }

    /**
     * Batch-delete send claims (mageos_workflow_send_log) past the send-log
     * retention window, measured on claimed_at — the instant the claim started
     * guarding, which is what the redelivery horizon is relative to.
     *
     * The configured value is floored at MIN_SEND_LOG_RETENTION_DAYS: a
     * misconfigured "1 day" would otherwise quietly re-enable double sends for
     * anything redelivered later than that.
     */
    private function pruneSendLog(): int
    {
        $days = (int) $this->scopeConfig->getValue(self::CONFIG_SEND_LOG_RETENTION_DAYS);
        if ($days <= 0) {
            $days = self::DEFAULT_SEND_LOG_RETENTION_DAYS;
        }
        $days = max($days, self::MIN_SEND_LOG_RETENTION_DAYS);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::SEND_LOG_TABLE);

        $totalDeleted = 0;
        do {
            $ids = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->from($table, ['send_log_id'])
                    ->where('claimed_at < ?', $cutoff)
                    ->limit(self::BATCH_SIZE)
            ));
            if ($ids === []) {
                break;
            }
            $totalDeleted += $connection->delete($table, ['send_log_id IN (?)' => $ids]);
        } while (count($ids) === self::BATCH_SIZE);

        return $totalDeleted;
    }

    /**
     * Batch-delete debounce slots too old to ever match a current time bucket
     * again (bucket = intdiv(now, window), so one window is the true horizon).
     */
    private function pruneDebounceSlots(): int
    {
        $window = (int) $this->scopeConfig->getValue(Dispatcher::CONFIG_DEBOUNCE_WINDOW);
        if ($window <= 0) {
            $window = Dispatcher::DEFAULT_DEBOUNCE_WINDOW;
        }
        $keepSeconds = max($window * self::DEBOUNCE_SAFETY_MULTIPLIER, self::DEBOUNCE_MIN_KEEP_SECONDS);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $keepSeconds);

        $connection = $this->resourceConnection->getConnection();
        $debounceTable = $this->resourceConnection->getTableName(self::DEBOUNCE_TABLE);

        $totalDeleted = 0;
        do {
            $debounceIds = array_map('intval', $connection->fetchCol(
                $connection->select()
                    ->from($debounceTable, ['debounce_id'])
                    ->where('created_at < ?', $cutoff)
                    ->limit(self::BATCH_SIZE)
            ));
            if ($debounceIds === []) {
                break;
            }
            $totalDeleted += $connection->delete($debounceTable, ['debounce_id IN (?)' => $debounceIds]);
        } while (count($debounceIds) === self::BATCH_SIZE);

        return $totalDeleted;
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
