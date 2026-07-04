<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Model\Aggregation\BatchStoreInterface;

/**
 * In-memory BatchStoreInterface for the accumulator/flush unit tests. Models
 * the atomic-claim and dedupe semantics of the production adapter:
 * openBatch/upsertItem are idempotent on their unique keys, and
 * claimDueForFlush transitions open→flushing exactly once per row (so a second
 * claimer sees nothing — the "two sweepers, one winner" property).
 */
class FakeBatchStore implements BatchStoreInterface
{
    /** @var array<int, array<string, mixed>> batch_id => row */
    private array $batches = [];

    /** @var array<int, array<int, string>> batch_id => (entity_id => snapshot json) */
    private array $items = [];

    private int $nextBatchId = 1;

    public function openBatch(int $workflowId, string $windowKey, string $flushDueAt, int $storeId): int
    {
        foreach ($this->batches as $id => $batch) {
            if ($batch['workflow_id'] === $workflowId && $batch['window_key'] === $windowKey) {
                return $id;
            }
        }
        $id = $this->nextBatchId++;
        $this->batches[$id] = [
            'batch_id' => $id,
            'workflow_id' => $workflowId,
            'window_key' => $windowKey,
            'status' => 'open',
            'item_count' => 0,
            'store_id' => $storeId,
            'execution_id' => null,
            'opened_at' => gmdate('Y-m-d H:i:s'),
            'flush_due_at' => $flushDueAt,
            'flushing_at' => null,
            'flushed_at' => null,
        ];
        $this->items[$id] = [];
        return $id;
    }

    public function findOpenBatch(int $workflowId): ?array
    {
        $found = null;
        foreach ($this->batches as $batch) {
            if ($batch['workflow_id'] === $workflowId && $batch['status'] === 'open') {
                $found = $batch;
            }
        }
        return $found;
    }

    public function upsertItem(int $batchId, int $entityId, string $snapshotJson): bool
    {
        $isNew = !isset($this->items[$batchId][$entityId]);
        $this->items[$batchId][$entityId] = $snapshotJson;
        return $isNew;
    }

    public function syncItemCount(int $batchId): int
    {
        $count = count($this->items[$batchId] ?? []);
        $this->batches[$batchId]['item_count'] = $count;
        return $count;
    }

    public function claimDueForFlush(string $now): array
    {
        $claimed = [];
        foreach ($this->batches as $id => $batch) {
            if ($batch['status'] === 'open' && $batch['flush_due_at'] <= $now) {
                $this->batches[$id]['status'] = 'flushing';
                $this->batches[$id]['flushing_at'] = $now;
                $claimed[] = $this->batches[$id];
            }
        }
        return $claimed;
    }

    public function reclaimStaleFlushing(string $cutoff): array
    {
        $claimed = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->batches as $id => $batch) {
            if ($batch['status'] === 'flushing'
                && $batch['flushing_at'] !== null
                && $batch['flushing_at'] < $cutoff
            ) {
                $this->batches[$id]['flushing_at'] = $now;
                $claimed[] = $this->batches[$id];
            }
        }
        return $claimed;
    }

    public function recordExecution(int $batchId, int $executionId): void
    {
        $this->batches[$batchId]['execution_id'] = $executionId;
    }

    public function markFlushed(int $batchId): void
    {
        $this->batches[$batchId]['status'] = 'flushed';
        $this->batches[$batchId]['flushed_at'] = gmdate('Y-m-d H:i:s');
    }

    public function carryOver(int $batchId, string $newFlushDueAt): void
    {
        $this->batches[$batchId]['status'] = 'open';
        $this->batches[$batchId]['flush_due_at'] = $newFlushDueAt;
        $this->batches[$batchId]['flushing_at'] = null;
    }

    public function loadItems(int $batchId, int $cap): array
    {
        $rows = $this->items[$batchId] ?? [];
        ksort($rows);
        $items = [];
        foreach (array_slice($rows, 0, $cap, true) as $snapshot) {
            $decoded = json_decode($snapshot, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $items;
    }

    // --- test helpers -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function batch(int $batchId): array
    {
        return $this->batches[$batchId];
    }

    public function itemCount(int $batchId): int
    {
        return count($this->items[$batchId] ?? []);
    }

    /**
     * Age a flushing batch's claim so the stale-flushing re-claim picks it up
     * (simulates a sweeper crash + grace period passing).
     */
    public function ageFlushing(int $batchId, string $timestamp): void
    {
        $this->batches[$batchId]['flushing_at'] = $timestamp;
    }

    /**
     * Force a batch's flush_due_at into the past so the next sweep claims it
     * (simulates the window closing).
     */
    public function makeDue(int $batchId): void
    {
        $this->batches[$batchId]['flush_due_at'] = '2000-01-01 00:00:00';
    }

    public function openBatchCount(): int
    {
        return count($this->batches);
    }
}
