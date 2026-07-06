<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;

/**
 * Production membership evaluator: wraps the shared two-phase
 * ConditionEvaluator, driving it snapshot-only.
 *
 * A transient (never persisted) execution row carries entity_id = 0, which
 * makes the evaluator's phase-2 hydration a no-op (the condition classes only
 * hydrate for a positive entity id) — so the root conditions are evaluated
 * against the flat projection alone, exactly the guarantee the save-time
 * ProfileCheck enforces. No queries, no execution persistence.
 */
class MembershipEvaluator implements MembershipEvaluatorInterface
{
    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly WorkflowExecutionInterfaceFactory $executionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function matches(WorkflowInterface $workflow, array $flatItem): bool
    {
        $conditions = (string) ($workflow->getConditionsSerialized() ?? '');
        if (trim($conditions) === '') {
            return true;
        }

        /** @var WorkflowExecutionInterface $execution */
        $execution = $this->executionFactory->create();
        $execution->setEntityId(0);
        $execution->setStoreId((int) ($flatItem['store_id'] ?? 0));

        $ctx = new ExecutionContext($execution, $flatItem, [], [
            'id' => (int) $workflow->getWorkflowId(),
            'entity_type' => $workflow->getEntityType(),
        ]);

        return $this->conditionEvaluator->evaluateSerialized(
            $conditions,
            $workflow->getEntityType(),
            $ctx,
            false
        );
    }
}
