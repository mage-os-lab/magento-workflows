<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Model;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use Psr\Log\LoggerInterface;

/**
 * In-process root-condition check for the QueryRunner's FALLBACK path.
 *
 * When a schedule workflow's condition tree cannot be index-mapped, the
 * runner pages the repository unfiltered — without this check it would
 * dispatch one execution row + queue message per scanned row up to the match
 * cap, mostly for the engine to skip at evaluation time. This class answers
 * the same question the engine's first-run root gate asks
 * (Executor::execute -> ConditionEvaluator::evaluate), before any execution
 * exists, so non-matching rows are skipped for the cost of an in-process
 * evaluation instead of a full dispatch/consume/skip round trip.
 *
 * Unlike the aggregation MembershipEvaluator — which pins entity_id = 0 to
 * force snapshot-only evaluation — the transient execution here carries the
 * REAL entity id, so the evaluator's phase-2 hydration stays available for
 * conditions the flat snapshot cannot answer. That keeps this check's verdict
 * identical to the verdict the engine would reach, which is what makes
 * skipping safe: a snapshot-only check could return a false negative on a
 * hydration-dependent condition and silently starve the workflow.
 *
 * Fail-open by contract: any evaluation error means DISPATCH (the engine
 * re-evaluates per execution anyway and is the authority). A false positive
 * costs one skipped execution — today's behavior for every row; a false
 * negative would silently drop real work, so it is never an acceptable
 * outcome of an error here. The transient execution row is never persisted.
 */
class RootConditionPreFilter
{
    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly WorkflowExecutionInterfaceFactory $executionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload the dispatch payload (flat entity
     *        snapshot including entity_id) this row would be dispatched with
     */
    public function matches(WorkflowInterface $workflow, int $entityId, array $payload): bool
    {
        $conditions = (string) ($workflow->getConditionsSerialized() ?? '');
        if (trim($conditions) === '') {
            return true;
        }

        try {
            /** @var WorkflowExecutionInterface $execution */
            $execution = $this->executionFactory->create();
            $execution->setEntityId($entityId);
            $execution->setStoreId((int) ($payload['store_id'] ?? 0));

            $ctx = new ExecutionContext($execution, $payload, [], [
                'id' => (int) $workflow->getWorkflowId(),
                'entity_type' => $workflow->getEntityType(),
            ]);

            return $this->conditionEvaluator->evaluate($workflow, $ctx);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'RootConditionPreFilter: evaluation failed for workflow #%d entity %d; '
                . 'dispatching anyway (the engine re-evaluates): %s',
                (int) $workflow->getWorkflowId(),
                $entityId,
                $e->getMessage()
            ));
            return true;
        }
    }
}
