<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * B2 flush sweep, riding the one-minute resume-sweeper cadence.
 *
 * Idempotency is the batch row itself, following resumeWaiting's
 * write-before-publish discipline:
 *   1. claim due-and-open batches (status open→flushing, atomic conditional
 *      UPDATE — one sweeper wins each row);
 *   2. create the flush execution and record its id on the batch row BEFORE
 *      publishing;
 *   3. publish, then stamp `flushed`.
 * A crash between (2) and (3) leaves the batch in `flushing` with a recorded
 * execution id; the stale-flushing re-claim re-publishes THAT execution rather
 * than creating a second (the time-bucket debounce provably can't dedupe a
 * retry that lands in a different bucket).
 *
 * min_items (provisional default): an interval batch under the minimum carries
 * its items into the next window (bumped flush_due_at, back to `open`); a
 * schedule batch drops with a debug log.
 */
class BatchFlusher
{
    public const TOPIC_EXECUTE = 'mageos.workflow.execute';

    /**
     * A `flushing` batch older than this (a crashed sweeper) is re-claimed.
     */
    public const FLUSH_GRACE_MINUTES = 5;

    public function __construct(
        private readonly BatchStoreInterface $store,
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly BatchExecutionFactoryInterface $executionFactory,
        private readonly BatchContextBuilder $contextBuilder,
        private readonly WindowKeyCalculator $windowKeyCalculator,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int the number of batches flushed to an execution this pass
     */
    public function flush(): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $flushed = 0;

        foreach ($this->store->claimDueForFlush($now) as $batch) {
            $flushed += $this->process($batch) ? 1 : 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::FLUSH_GRACE_MINUTES * 60);
        foreach ($this->store->reclaimStaleFlushing($cutoff) as $batch) {
            $flushed += $this->process($batch) ? 1 : 0;
        }

        return $flushed;
    }

    /**
     * @param array<string, mixed> $batch
     * @return bool true when a batch execution was published
     */
    private function process(array $batch): bool
    {
        $batchId = (int) $batch['batch_id'];
        $workflowId = (int) $batch['workflow_id'];

        try {
            $workflow = $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException $e) {
            $this->store->markFlushed($batchId);
            $this->logger->warning(sprintf('Batch flush skipped: workflow %d not found; batch %d closed', $workflowId, $batchId));
            return false;
        }

        $config = AggregationConfig::fromJson($workflow->getAggregation());
        $executionId = (int) ($batch['execution_id'] ?? 0);

        if ($executionId <= 0) {
            $count = (int) ($batch['item_count'] ?? 0);
            $minItems = $config?->getMinItems() ?? AggregationConfig::DEFAULT_MIN_ITEMS;
            if ($count < $minItems) {
                $this->handleUnderMinimum($batch, $config);
                return false;
            }

            $itemCap = $config?->getItemCap() ?? AggregationConfig::DEFAULT_ITEM_CAP;
            $items = $this->store->loadItems($batchId, $itemCap);
            $context = $this->contextBuilder->build(
                $count,
                $items,
                ['from' => $batch['opened_at'] ?? null, 'to' => $batch['flush_due_at'] ?? null],
                $itemCap
            );

            $executionId = $this->executionFactory->create($workflow, $context, (int) ($batch['store_id'] ?? 0));
            // Write-before-publish: the id is durable BEFORE the publish so a
            // retry re-publishes rather than re-creating.
            $this->store->recordExecution($batchId, $executionId);
        }

        try {
            $this->publisher->publish(self::TOPIC_EXECUTE, (string) $executionId);
        } catch (\Throwable $e) {
            // Leave the batch in `flushing` with its recorded execution id; the
            // stale-flushing re-claim retries and re-publishes the same one.
            $this->logger->error(sprintf(
                'Batch flush could not publish execution %d for batch %d: %s',
                $executionId,
                $batchId,
                $e->getMessage()
            ), ['exception' => $e]);
            return false;
        }

        $this->store->markFlushed($batchId);
        $this->logger->info('batch_flushed', [
            'workflow_id' => $workflowId,
            'batch_id' => $batchId,
            'execution_id' => $executionId,
        ]);
        return true;
    }

    /**
     * @param array<string, mixed> $batch
     */
    private function handleUnderMinimum(array $batch, ?AggregationConfig $config): void
    {
        $batchId = (int) $batch['batch_id'];
        $isInterval = $config !== null && $config->getWindowType() === AggregationConfig::WINDOW_INTERVAL;

        if ($isInterval) {
            // Carry items into the next window: re-open with a bumped due time.
            $window = $this->windowKeyCalculator->resolve($config, time());
            $this->store->carryOver($batchId, $window['flush_due_at']);
            $this->logger->debug('batch_carried_under_min', [
                'workflow_id' => (int) $batch['workflow_id'],
                'batch_id' => $batchId,
                'items' => (int) ($batch['item_count'] ?? 0),
            ]);
            return;
        }

        // Schedule mode: drop with a debug log.
        $this->store->markFlushed($batchId);
        $this->logger->debug('batch_dropped_under_min', [
            'workflow_id' => (int) $batch['workflow_id'],
            'batch_id' => $batchId,
            'items' => (int) ($batch['item_count'] ?? 0),
        ]);
    }
}
