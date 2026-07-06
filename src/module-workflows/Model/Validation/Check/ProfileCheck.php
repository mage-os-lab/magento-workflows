<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Api\BatchCapableActionInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Rule\AttributeClassifier;
use MageOS\Workflows\Model\Trigger\SnapshotShapeProvider;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Restricted definition-profile check per workflow kind (F2). Inert until a
 * profile is registered for a kind: the 'standard' kind carries no profile, so
 * every definition passes untouched.
 *
 * Batch aggregation (05) registers the 'aggregated' profile via di.xml. Its
 * restricted profile — the single design move that keeps the executor
 * batch-agnostic — is enforced entirely here at save time, with
 * merchant-readable messages carrying stable codes:
 *
 *   - step-type allowlist: action / delay / branch / switch / stop (no wait —
 *     a wait step parks per entity and can never wake in a batch execution);
 *   - actions must be batch-capable (BatchCapableActionInterface marker —
 *     per-entity mutating actions belong in fan-out, not aggregation);
 *   - revalidate_entity is invalid (batch executions carry entity_id=0 and
 *     never re-hydrate — snapshot-only evaluation only);
 *   - root conditions (and branch/switch gates) must classify fully
 *     in_snapshot: no hydration-forcing node types (cross-entity/relation),
 *     and no attributes outside the declared trigger snapshot — the
 *     accumulation path runs per event during a storm and must be zero-query
 *     (F4 AttributeClassifier, its first production wiring).
 */
class ProfileCheck implements CheckInterface
{
    public const CODE_STEP_TYPE_FORBIDDEN = 'PROFILE_STEP_TYPE_FORBIDDEN';
    public const CODE_ACTION_FORBIDDEN = 'PROFILE_ACTION_FORBIDDEN';
    public const CODE_ACTION_NOT_BATCH_CAPABLE = 'PROFILE_ACTION_NOT_BATCH_CAPABLE';
    public const CODE_CONDITION_NOT_IN_SNAPSHOT = 'PROFILE_CONDITION_NOT_IN_SNAPSHOT';
    public const CODE_REVALIDATE_FORBIDDEN = 'PROFILE_REVALIDATE_FORBIDDEN';

    /**
     * @param array<string, array{
     *     step_types?: string[],
     *     actions?: string[],
     *     require_batch_capable_actions?: bool,
     *     forbid_revalidate?: bool,
     *     require_in_snapshot_conditions?: bool
     * }> $profiles workflow kind => profile; a kind with no entry is unrestricted
     */
    public function __construct(
        private readonly array $profiles = [],
        private readonly ?ActionPool $actionPool = null,
        private readonly ?AttributeClassifier $attributeClassifier = null,
        private readonly ?SnapshotShapeProvider $snapshotShapeProvider = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $definition = $subject->getDefinition();
        $profile = $this->profiles[$context->getWorkflowKind()] ?? null;
        if ($definition === null || $profile === null) {
            return [];
        }

        $messages = [];
        $allowedTypes = $profile['step_types'] ?? null;
        $allowedActions = $profile['actions'] ?? null;
        $requireBatchCapable = (bool) ($profile['require_batch_capable_actions'] ?? false);
        $forbidRevalidate = (bool) ($profile['forbid_revalidate'] ?? false);

        foreach ($definition->getSteps() as $stepKey => $step) {
            $stepKey = (string) $stepKey;
            $type = (string) ($step['type'] ?? '');

            if (is_array($allowedTypes) && !in_array($type, $allowedTypes, true)) {
                $messages[] = ValidationMessage::error(
                    self::CODE_STEP_TYPE_FORBIDDEN,
                    (string) __(
                        'Step "%1": "%2" steps are not allowed in %3 workflows.',
                        $stepKey,
                        $type,
                        $context->getWorkflowKind()
                    ),
                    $stepKey
                );
            }

            if ($type === Definition::STEP_ACTION) {
                $action = (string) ($step['action'] ?? '');
                if (is_array($allowedActions) && !in_array($action, $allowedActions, true)) {
                    $messages[] = ValidationMessage::error(
                        self::CODE_ACTION_FORBIDDEN,
                        (string) __(
                            'Step "%1": action "%2" is not allowed for %3 workflows.',
                            $stepKey,
                            $action,
                            $context->getWorkflowKind()
                        ),
                        $stepKey
                    );
                }
                if ($requireBatchCapable && !$this->isBatchCapable($action)) {
                    $messages[] = ValidationMessage::error(
                        self::CODE_ACTION_NOT_BATCH_CAPABLE,
                        (string) __(
                            'Step "%1": action "%2" cannot run over a batch. Aggregated workflows can '
                            . 'only use batch-safe actions (email, webhook, admin notification, set variable).',
                            $stepKey,
                            $action
                        ),
                        $stepKey
                    );
                }
            }

            if ($forbidRevalidate && $this->hasRevalidate($step)) {
                $messages[] = ValidationMessage::error(
                    self::CODE_REVALIDATE_FORBIDDEN,
                    (string) __(
                        'Step "%1": re-validating the entity is not available in aggregated workflows — '
                        . 'batch executions have no single entity and evaluate the event snapshot only. '
                        . 'Set "revalidate_entity": false explicitly (branch/switch steps default to true).',
                        $stepKey
                    ),
                    $stepKey
                );
            }
        }

        if ((bool) ($profile['require_in_snapshot_conditions'] ?? false)) {
            foreach ($this->collectConditionMessages($subject, $definition, $context) as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    private function isBatchCapable(string $actionCode): bool
    {
        if ($actionCode === '' || $this->actionPool === null || !$this->actionPool->has($actionCode)) {
            // Unknown actions are ActionCodesCheck's job; don't double-report here.
            return true;
        }
        $action = $this->actionPool->get($actionCode);
        if ($action instanceof BatchCapableActionInterface) {
            return true;
        }
        return method_exists($action, 'supportsBatch') && $action->supportsBatch() === true;
    }

    /**
     * Whether the step would re-hydrate at run time. Branch/switch steps
     * default to revalidate_entity = true when the key is ABSENT (the
     * executor's `?? true` convention, docs/06 delay semantics) — so only an
     * explicit false is safe in an aggregated workflow; an omitted key must
     * be flagged too, or a REST/CLI-authored definition passes validation and
     * then fail-closes every branch at run time against entity_id = 0.
     * Other step types never consult the flag.
     *
     * @param array<string, mixed> $step
     */
    private function hasRevalidate(array $step): bool
    {
        $type = (string) ($step['type'] ?? '');
        if (!in_array($type, [Definition::STEP_BRANCH, Definition::STEP_SWITCH], true)) {
            return ($step['revalidate_entity'] ?? null) === true;
        }
        return (bool) ($step['revalidate_entity'] ?? true);
    }

    /**
     * Root conditions plus every embedded branch/switch gate must classify
     * fully in-snapshot.
     *
     * @return ValidationMessage[]
     */
    private function collectConditionMessages(
        ValidationSubject $subject,
        Definition $definition,
        ValidationContext $context
    ): array {
        if ($this->attributeClassifier === null) {
            return [];
        }
        $snapshotAttributes = $this->snapshotShapeProvider !== null && $context->getEntityType() !== null
            ? $this->snapshotShapeProvider->getAttributes($context->getEntityType())
            : null;

        $messages = [];
        $rootIssue = $this->classifyIssue($subject->getConditionsSerialized(), $snapshotAttributes);
        if ($rootIssue !== null) {
            $messages[] = ValidationMessage::error(
                self::CODE_CONDITION_NOT_IN_SNAPSHOT,
                (string) __(
                    'The workflow filter uses %1, which is not part of the event. Aggregated workflows '
                    . 'can only filter on data included in the event.',
                    $rootIssue
                )
            );
        }

        foreach ($definition->getSteps() as $stepKey => $step) {
            $stepKey = (string) $stepKey;
            $type = $step['type'] ?? null;
            if ($type === Definition::STEP_BRANCH) {
                $issue = $this->classifyIssue($step['conditions_serialized'] ?? null, $snapshotAttributes);
                if ($issue !== null) {
                    $messages[] = ValidationMessage::error(
                        self::CODE_CONDITION_NOT_IN_SNAPSHOT,
                        (string) __(
                            'Step "%1": the branch condition uses %2, which is not part of the event.',
                            $stepKey,
                            $issue
                        ),
                        $stepKey
                    );
                }
            }
            if ($type === Definition::STEP_SWITCH) {
                foreach ((array) ($step['cases'] ?? []) as $case) {
                    if (!is_array($case)) {
                        continue;
                    }
                    $issue = $this->classifyIssue($case['conditions_serialized'] ?? null, $snapshotAttributes);
                    if ($issue !== null) {
                        $caseKey = (string) ($case['key'] ?? '');
                        $messages[] = ValidationMessage::error(
                            self::CODE_CONDITION_NOT_IN_SNAPSHOT,
                            (string) __(
                                'Step "%1" case "%2": the condition uses %3, which is not part of the event.',
                                $stepKey,
                                $caseKey,
                                $issue
                            ),
                            $stepKey,
                            'case:' . $caseKey
                        );
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Returns a merchant-readable description of the first out-of-snapshot
     * reason (a forcing node type or an out-of-snapshot attribute), or null
     * when the tree is empty/absent or fully in-snapshot.
     *
     * @param string[]|null $snapshotAttributes null = shape unknown, skip
     *        per-attribute verification (still reject forcing node types)
     */
    private function classifyIssue(mixed $conditionsSerialized, ?array $snapshotAttributes): ?string
    {
        if (!is_scalar($conditionsSerialized) || trim((string) $conditionsSerialized) === '') {
            return null;
        }
        $tree = json_decode((string) $conditionsSerialized, true);
        if (!is_array($tree) || $tree === []) {
            return null;
        }

        $result = $this->attributeClassifier->classify($tree, $snapshotAttributes ?? []);
        if ($result['forcing_node_types'] !== []) {
            return (string) __('a related-entity / cross-entity condition');
        }
        if ($snapshotAttributes !== null && $result['needs_hydration'] !== []) {
            return implode(', ', $result['needs_hydration']);
        }
        return null;
    }
}
