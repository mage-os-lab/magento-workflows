<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Save-time type alignment for a trigger-level fan-out clause (F2 extension,
 * discovery/fan-out.md §2 / implementation/04-fan-out.md stage 1). No-ops for
 * the vast majority of workflows — those without a `fan_out` clause.
 *
 * The two alignment invariants that let the root conditions and actions be
 * authored naturally against the *target* entity:
 *
 *   trigger's source entity type  ==  relation's source entity type
 *   relation's target entity type ==  workflow's entity_type
 *
 * Plus one v1 restriction: schedule-type workflows may not fan out. The
 * scheduler already fans out over its match query; stacking a relation fan-out
 * on top is cap-multiplication nobody asked for (revisit on demand).
 *
 * Fail-closed: an unregistered relation or a malformed clause is a hard error —
 * a fan-out workflow that cannot resolve its relation would silently dispatch
 * nothing, which is worse than refusing to save.
 */
class FanOutAlignmentCheck implements CheckInterface
{
    public const CODE_MALFORMED = 'FAN_OUT_MALFORMED';
    public const CODE_AGGREGATED_UNSUPPORTED = 'FAN_OUT_AGGREGATED_UNSUPPORTED';
    public const CODE_SCHEDULE_UNSUPPORTED = 'FAN_OUT_SCHEDULE_UNSUPPORTED';
    public const CODE_UNKNOWN_RELATION = 'FAN_OUT_UNKNOWN_RELATION';
    public const CODE_SOURCE_MISMATCH = 'FAN_OUT_SOURCE_MISMATCH';
    public const CODE_TARGET_MISMATCH = 'FAN_OUT_TARGET_MISMATCH';

    public function __construct(
        private readonly RelationPool $relationPool,
        private readonly TriggerRegistry $triggerRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $fanOut = $subject->getFanOut();
        if ($fanOut === null || trim($fanOut) === '') {
            return [];
        }

        // Fan-out on an aggregated workflow would silently compose "expand
        // per target, then collapse into one digest" — a direction neither
        // feature documents or tests (batch-aggregation reserves only the
        // inverse, digest→fan-out, as future work). Refuse the combination
        // until it is a designed behavior.
        if ($context->getWorkflowKind() === ValidationContext::KIND_AGGREGATED) {
            return [ValidationMessage::error(
                self::CODE_AGGREGATED_UNSUPPORTED,
                (string) __(
                    'Aggregated (batch) workflows cannot use fan-out. Remove the fan-out clause '
                    . 'or the aggregation configuration.'
                )
            )];
        }

        $config = json_decode($fanOut, true);
        $relationCode = is_array($config) ? ($config['relation'] ?? null) : null;
        if (!is_string($relationCode) || $relationCode === '') {
            return [ValidationMessage::error(
                self::CODE_MALFORMED,
                (string) __(
                    'The fan-out configuration is not readable: it must be a JSON object naming a "relation".'
                )
            )];
        }

        // A schedule workflow already fans out over its match query; refuse the
        // stacked relation fan-out before judging types.
        if ($subject->getTriggerType() === WorkflowInterface::TRIGGER_TYPE_SCHEDULE) {
            return [ValidationMessage::error(
                self::CODE_SCHEDULE_UNSUPPORTED,
                (string) __(
                    'Schedule-type workflows cannot use fan-out: a scheduled workflow already runs '
                    . 'once per matched entity. Remove the fan-out clause or change the trigger type.'
                )
            )];
        }

        if (!$this->relationPool->has($relationCode)) {
            return [ValidationMessage::error(
                self::CODE_UNKNOWN_RELATION,
                (string) __('The fan-out relation "%1" is not registered.', $relationCode)
            )];
        }

        $relation = $this->relationPool->get($relationCode);
        $messages = [];

        $triggerEntity = $this->triggerSourceEntity($subject);
        if ($triggerEntity !== null && $triggerEntity !== $relation->getSourceEntityType()) {
            $messages[] = ValidationMessage::error(
                self::CODE_SOURCE_MISMATCH,
                (string) __(
                    'Fan-out mismatch: this workflow triggers on "%1", but the relation "%2" fans out '
                    . 'from "%3". The trigger entity and the relation source must be the same type.',
                    $triggerEntity,
                    $relationCode,
                    $relation->getSourceEntityType()
                )
            );
        }

        $entityType = (string) $subject->getEntityType();
        if ($entityType !== '' && $entityType !== $relation->getTargetEntityType()) {
            $messages[] = ValidationMessage::error(
                self::CODE_TARGET_MISMATCH,
                (string) __(
                    'Fan-out mismatch: the relation "%1" produces "%2" entities, but this workflow acts '
                    . 'on "%3". The workflow entity type must match the relation target so conditions and '
                    . 'actions run against each fanned-out entity.',
                    $relationCode,
                    $relation->getTargetEntityType(),
                    $entityType
                )
            );
        }

        return $messages;
    }

    /**
     * The source entity type the trigger fires on: for an event trigger it is
     * the declared entity of the async event; null when it cannot be determined
     * (unregistered event, or a non-event trigger), in which case the source
     * alignment is not judged here.
     */
    private function triggerSourceEntity(ValidationSubject $subject): ?string
    {
        if ($subject->getTriggerType() !== WorkflowInterface::TRIGGER_TYPE_EVENT) {
            return null;
        }
        $triggerRef = (string) $subject->getTriggerRef();
        if ($triggerRef === '') {
            return null;
        }
        $trigger = $this->triggerRegistry->getByEvent($triggerRef);
        $entity = $trigger['entity'] ?? null;

        return is_string($entity) && $entity !== '' ? $entity : null;
    }
}
