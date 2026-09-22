<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Cron;

use MageOS\Workflows\Model\Aggregation\BatchFlusher;
use Psr\Log\LoggerInterface;

/**
 * Batch flush sweep (05 B2), riding the one-minute resume-sweeper cadence: due
 * batches (flush_due_at passed) are claimed and released as one execution each,
 * with write-before-publish idempotency on the batch row. See BatchFlusher.
 */
class FlushBatches
{
    public function __construct(
        private readonly BatchFlusher $flusher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $flushed = $this->flusher->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Workflow batch flush sweep failed: ' . $e->getMessage(), ['exception' => $e]);
            return;
        }
        if ($flushed > 0) {
            $this->logger->info(sprintf('Workflow batch flush sweep released %d batch execution(s)', $flushed));
        }
    }
}
