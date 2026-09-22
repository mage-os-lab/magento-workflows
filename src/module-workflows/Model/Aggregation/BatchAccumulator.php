<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use Psr\Log\LoggerInterface;

/**
 * B2 accumulation: the dispatcher's batch branch. For an aggregated
 * window-mode workflow, an event does NOT create an execution — it evaluates
 * membership against the event snapshot only (zero-query, guaranteed by the
 * save-time ProfileCheck) and, on a match, upserts one projected item into the
 * workflow's open batch. Storm-proof: no hydration, no execution row, one
 * small deduped insert per event.
 *
 * Window resolution:
 *   - schedule → window_key is deterministic from "now" in the declared
 *     timezone, so every event converges on one batch row (openBatch is
 *     idempotent on UNIQUE(workflow_id, window_key)).
 *   - interval → the workflow's currently-open batch is reused; only the
 *     opening event (no open batch) mints a new window from "now".
 */
class BatchAccumulator
{
    public function __construct(
        private readonly BatchStoreInterface $store,
        private readonly MembershipEvaluatorInterface $membershipEvaluator,
        private readonly ItemProjector $itemProjector,
        private readonly WindowKeyCalculator $windowKeyCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload the pre-hydrated event snapshot
     * @return bool true when the event was accumulated (a member)
     */
    public function accumulate(WorkflowInterface $workflow, AggregationConfig $config, array $payload): bool
    {
        $workflowId = (int) $workflow->getWorkflowId();
        $entityId = $this->extractEntityId($payload);
        if ($entityId <= 0) {
            return false;
        }

        if (!$this->membershipEvaluator->matches($workflow, $payload)) {
            return false;
        }

        try {
            $batchId = $this->resolveBatchId($workflow, $config);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error(sprintf(
                'Workflow batch accumulation skipped: bad window policy for workflow %d: %s',
                $workflowId,
                $e->getMessage()
            ));
            return false;
        }

        $fields = $this->itemProjector->fieldsFor($config->getProjection(), $workflow->getConditionsSerialized());
        $item = $this->itemProjector->project($payload, $fields);
        $item['entity_id'] = $entityId;

        $inserted = $this->store->upsertItem(
            $batchId,
            $entityId,
            (string) json_encode($item, JSON_UNESCAPED_SLASHES)
        );
        if ($inserted) {
            $this->store->syncItemCount($batchId);
        }

        return true;
    }

    private function resolveBatchId(WorkflowInterface $workflow, AggregationConfig $config): int
    {
        $workflowId = (int) $workflow->getWorkflowId();
        $storeId = 0;

        if ($config->getWindowType() === AggregationConfig::WINDOW_INTERVAL) {
            $open = $this->store->findOpenBatch($workflowId);
            if ($open !== null) {
                return (int) $open['batch_id'];
            }
        }

        $window = $this->windowKeyCalculator->resolve($config, time());
        return $this->store->openBatch($workflowId, $window['window_key'], $window['flush_due_at'], $storeId);
    }

    private function extractEntityId(array $payload): int
    {
        return (int) ($payload['entity_id'] ?? $payload['id'] ?? 0);
    }
}
