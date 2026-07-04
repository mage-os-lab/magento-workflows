<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;

/**
 * Entry point for a synchronous, side-effect-free dry-run (Approach B,
 * discovery §3): "what would this definition do to entity X right now".
 *
 * It runs the F2 pipeline first — but only the dry-run check subset: Structural,
 * Graph, ActionCodes, ConditionsShape. ActionAuthorizationCheck is deliberately
 * excluded (dry-run is not an authoring path — a ::dry_run holder who lacks one
 * action-authoring resource must still preview it). A definition with blocking
 * findings returns those findings and no trace: dry-run never fabricates a walk
 * over a broken graph. A sound definition is walked by the {@see Walker} over a
 * simulation {@see ExecutionContext}, and the flat {@see Trace} is returned.
 *
 * Zero side effects: no execution row, no queue, no real secret resolved into
 * anything observable (the walker's resolver is the redacting virtualType).
 * Persistence of admin dry-runs is a separate, opt-out concern handled by the
 * admin surface — this service is pure.
 */
class DryRunService
{
    public const MAX_DISTINCT_STEPS = PathExplorer::DEFAULT_MAX_DISTINCT_STEPS;

    public function __construct(
        private readonly WorkflowValidator $validator,
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly HydrationProviderInterface $hydrationProvider,
        private readonly Walker $walker,
        private readonly SimulationContextFactory $contextFactory
    ) {
    }

    public function run(DryRunRequest $request): Trace
    {
        $header = $this->header($request);

        // 1. F2 pipeline, dry-run subset. A broken graph short-circuits with the
        //    findings and no trace.
        $result = $this->validator->validate(
            new ValidationSubject($request->getDefinitionJson(), $request->getConditionsSerialized()),
            new ValidationContext(ValidationContext::MODE_ADMIN_CONTEXT, ValidationContext::KIND_STANDARD, true)
        );
        if ($result->hasErrors()) {
            return new Trace($header['workflow'], $header['entity'], $result->getMessages());
        }

        $definition = Definition::fromJson($request->getDefinitionJson());

        // 2. Missing entity is a trace-level error up front, mirroring the
        //    production skipped-on-missing-entity semantics (real-entity runs only).
        if (!$request->isSynthetic()
            && $request->getEntityId() !== null && $request->getEntityId() > 0
            && $this->hydrationProvider->getEntity($request->getEntityType(), $request->getEntityId(), false) === null
        ) {
            return new Trace($header['workflow'], $header['entity'], [
                ValidationMessage::error(
                    'DRY_RUN_ENTITY_NOT_FOUND',
                    (string) __(
                        'No %1 with ID %2 was found to run against.',
                        $request->getEntityType(),
                        (int) $request->getEntityId()
                    )
                ),
            ]);
        }

        $ctx = $this->buildContext($request);

        // 3. Root condition gate: an entity the workflow would not fire on
        //    yields an empty, flagged trace — honest about the skip.
        if (!$this->rootConditionsMatch($request, $ctx)) {
            return new Trace($header['workflow'], $header['entity'], [], [], true);
        }

        $entryKey = $definition->getEntryKey();
        if ($entryKey === null) {
            return new Trace($header['workflow'], $header['entity']);
        }

        $walk = $this->walker->walk(
            $definition,
            $ctx,
            $request->getEntityType(),
            $entryKey,
            self::MAX_DISTINCT_STEPS
        );

        return new Trace(
            $header['workflow'],
            $header['entity'],
            [],
            $walk['steps'],
            false,
            $walk['truncated']
        );
    }

    private function rootConditionsMatch(DryRunRequest $request, ExecutionContext $ctx): bool
    {
        $serialized = (string) ($request->getConditionsSerialized() ?? '');
        if (trim($serialized) === '') {
            return true;
        }
        try {
            return $this->conditionEvaluator->evaluateSerialized(
                $serialized,
                $request->getEntityType(),
                $ctx,
                false
            );
        } catch (\InvalidArgumentException $e) {
            // Malformed root conditions are a ConditionsShapeCheck concern; if
            // one slips through, do not claim a confident skip — walk anyway.
            return true;
        }
    }

    private function buildContext(DryRunRequest $request): ExecutionContext
    {
        $trigger = $request->isSynthetic()
            ? (array) $request->getTriggerPayload()
            : ['entity_id' => (int) ($request->getEntityId() ?? 0)];

        $entityId = (int) ($trigger['entity_id'] ?? $request->getEntityId() ?? 0);
        $storeId = (int) ($trigger['store_id'] ?? 0);

        return $this->contextFactory->create(
            (int) ($request->getWorkflowId() ?? 0),
            $request->getEntityType(),
            $entityId,
            $storeId,
            $trigger,
            $request->getWorkflowName()
        );
    }

    /**
     * @return array{workflow: array{id?: int, name?: string}, entity: array{type: string, id?: ?int}}
     */
    private function header(DryRunRequest $request): array
    {
        $workflow = ['name' => $request->getWorkflowName()];
        if ($request->getWorkflowId() !== null) {
            $workflow['id'] = (int) $request->getWorkflowId();
        }
        return [
            'workflow' => $workflow,
            'entity' => ['type' => $request->getEntityType(), 'id' => $request->getEntityId()],
        ];
    }
}
