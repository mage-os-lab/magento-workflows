<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use Magento\Framework\App\ResourceConnection;

/**
 * Production BatchStoreInterface adapter over ResourceConnection.
 *
 * Item and batch upserts use insertOnDuplicate (mirroring
 * StockThresholdDetector / RunScheduledWorkflows) — NOT the dispatcher's
 * insert-and-catch debounce idiom. The flush claim is an atomic conditional
 * UPDATE (open→flushing), the same race-safe claim as Dispatcher::resumeWaiting.
 */
class BatchStore implements BatchStoreInterface
{
    private const BATCH_TABLE = 'mageos_workflow_batch';
    private const ITEM_TABLE = 'mageos_workflow_batch_item';

    /**
     * Batches claimed per sweep pass (bounds a single cron tick's work).
     */
    private const CLAIM_BATCH = 200;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function openBatch(int $workflowId, string $windowKey, string $flushDueAt, int $storeId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'workflow_id' => $workflowId,
                'window_key' => $windowKey,
                'status' => 'open',
                'item_count' => 0,
                'store_id' => $storeId,
                'flush_due_at' => $flushDueAt,
                'opened_at' => gmdate('Y-m-d H:i:s'),
            ],
            // The unique key exists; touch a no-op column so the row survives.
            ['window_key']
        );

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['batch_id'])
                ->where('workflow_id = ?', $workflowId)
                ->where('window_key = ?', $windowKey)
        );
    }

    public function findOpenBatch(int $workflowId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('workflow_id = ?', $workflowId)
                ->where('status = ?', 'open')
                ->order('batch_id DESC')
                ->limit(1)
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    public function upsertItem(int $batchId, int $entityId, string $snapshotJson): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ITEM_TABLE);

        $affected = $connection->insertOnDuplicate(
            $table,
            [
                'batch_id' => $batchId,
                'entity_id' => $entityId,
                'snapshot' => $snapshotJson,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['snapshot']
        );
        // insertOnDuplicate: 1 affected row = fresh insert, 2 = existing updated.
        return (int) $affected === 1;
    }

    public function syncItemCount(int $batchId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $batchTable = $this->resourceConnection->getTableName(self::BATCH_TABLE);
        $itemTable = $this->resourceConnection->getTableName(self::ITEM_TABLE);

        $count = (int) $connection->fetchOne(
            $connection->select()
                ->from($itemTable, ['COUNT(*)'])
                ->where('batch_id = ?', $batchId)
        );
        $connection->update($batchTable, ['item_count' => $count], ['batch_id = ?' => $batchId]);
        return $count;
    }

    public function claimDueForFlush(string $now): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);

        $ids = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($table, ['batch_id'])
                ->where('status = ?', 'open')
                ->where('flush_due_at <= ?', $now)
                ->limit(self::CLAIM_BATCH)
        ));

        $claimed = [];
        foreach ($ids as $batchId) {
            $ok = $connection->update(
                $table,
                ['status' => 'flushing', 'flushing_at' => $now],
                ['batch_id = ?' => $batchId, 'status = ?' => 'open']
            );
            if ($ok === 1) {
                $row = $connection->fetchRow($connection->select()->from($table)->where('batch_id = ?', $batchId));
                if (is_array($row)) {
                    $claimed[] = $row;
                }
            }
        }
        return $claimed;
    }

    public function reclaimStaleFlushing(string $cutoff): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);

        $ids = array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($table, ['batch_id'])
                ->where('status = ?', 'flushing')
                ->where('flushing_at < ?', $cutoff)
                ->limit(self::CLAIM_BATCH)
        ));

        $now = gmdate('Y-m-d H:i:s');
        $claimed = [];
        foreach ($ids as $batchId) {
            $ok = $connection->update(
                $table,
                ['flushing_at' => $now],
                ['batch_id = ?' => $batchId, 'status = ?' => 'flushing', 'flushing_at < ?' => $cutoff]
            );
            if ($ok === 1) {
                $row = $connection->fetchRow($connection->select()->from($table)->where('batch_id = ?', $batchId));
                if (is_array($row)) {
                    $claimed[] = $row;
                }
            }
        }
        return $claimed;
    }

    public function recordExecution(int $batchId, int $executionId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);
        $connection->update($table, ['execution_id' => $executionId], ['batch_id = ?' => $batchId]);
    }

    public function markFlushed(int $batchId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);
        $connection->update(
            $table,
            ['status' => 'flushed', 'flushed_at' => gmdate('Y-m-d H:i:s')],
            ['batch_id = ?' => $batchId]
        );
    }

    public function carryOver(int $batchId, string $newFlushDueAt): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::BATCH_TABLE);
        $connection->update(
            $table,
            ['status' => 'open', 'flush_due_at' => $newFlushDueAt, 'flushing_at' => null],
            ['batch_id = ?' => $batchId]
        );
    }

    public function loadItems(int $batchId, int $cap): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::ITEM_TABLE);

        $rows = $connection->fetchCol(
            $connection->select()
                ->from($table, ['snapshot'])
                ->where('batch_id = ?', $batchId)
                ->order('entity_id ASC')
                ->limit($cap)
        );

        $items = [];
        foreach ($rows as $snapshot) {
            $decoded = json_decode((string) $snapshot, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $items;
    }
}
