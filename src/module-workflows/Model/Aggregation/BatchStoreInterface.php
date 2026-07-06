<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

/**
 * Persistence port for the B2 accumulator and flush sweep — the seam that
 * keeps the accumulation/flush *logic* (dedupe, atomic claim, write-before-
 * publish idempotency) testable without a database. The production adapter
 * (BatchStore) implements it over ResourceConnection with insertOnDuplicate
 * and conditional UPDATEs; unit tests use an in-memory fake.
 *
 * A batch row is `{batch_id, workflow_id, window_key, status(open|flushing|
 * flushed), item_count, store_id, execution_id, flush_due_at, …}`.
 */
interface BatchStoreInterface
{
    /**
     * Ensure a batch row for (workflow_id, window_key) exists and return its
     * id. Idempotent via UNIQUE(workflow_id, window_key): concurrent openers
     * converge on one row (insertOnDuplicate, mirroring StockThresholdDetector
     * — NOT the dispatcher's insert-and-catch debounce idiom).
     */
    public function openBatch(int $workflowId, string $windowKey, string $flushDueAt, int $storeId): int;

    /**
     * The workflow's currently-open batch (status=open), if any — used by the
     * interval policy so events within a window append to the same batch
     * rather than minting a new window_key each time.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenBatch(int $workflowId): ?array;

    /**
     * Upsert one item, deduped on UNIQUE(batch_id, entity_id) via
     * insertOnDuplicate — an entity appears once per batch. Returns true when a
     * NEW item row was inserted (so the caller can maintain item_count).
     */
    public function upsertItem(int $batchId, int $entityId, string $snapshotJson): bool;

    /**
     * Recompute and persist item_count from the item rows (authoritative count).
     */
    public function syncItemCount(int $batchId): int;

    /**
     * Atomically claim due-and-open batches for flushing (status open→flushing,
     * conditional UPDATE — the same claim idiom as resumeWaiting). Only one
     * caller wins each row.
     *
     * @return array<int, array<string, mixed>> the rows this caller claimed
     */
    public function claimDueForFlush(string $now): array;

    /**
     * Re-claim batches stuck in `flushing` past the grace cutoff (a sweeper
     * crashed mid-flush). These may already carry a recorded execution_id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reclaimStaleFlushing(string $cutoff): array;

    /**
     * Record the flush execution id on the batch row BEFORE publishing
     * (write-before-publish): a retry that finds it re-publishes rather than
     * creating a second execution.
     */
    public function recordExecution(int $batchId, int $executionId): void;

    /**
     * Stamp the batch flushed (after a successful publish).
     */
    public function markFlushed(int $batchId): void;

    /**
     * Return an under-minimum interval batch to `open` with a bumped
     * flush_due_at so its items carry into the next window.
     */
    public function carryOver(int $batchId, string $newFlushDueAt): void;

    /**
     * Load up to $cap item snapshots (decoded), ordered by entity id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function loadItems(int $batchId, int $cap): array;
}
