<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Validation\Check;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Save-time sanity of the trigger reference (F2, docs/05-triggers.md). Before
 * this check a schedule workflow with a typo'd cron expression, or an event
 * workflow pointing at an event nobody dispatches, saved cleanly and simply
 * never fired — the failure only surfaced in a cron log
 * (RunScheduledWorkflows) or, for events, not at all.
 *
 * Severity is deliberately split:
 *
 * - An EMPTY trigger_ref on an event- or schedule-type workflow is a hard
 *   error: there is nothing to subscribe to or evaluate, so the workflow is
 *   inert by construction.
 * - An event ref that the TriggerRegistry does not describe is only a WARNING.
 *   The registry is UI metadata (workflow_triggers.xml: label, entity,
 *   resolver), not the dispatch authority — a third-party module may publish
 *   an async event it never declared, and refusing that save would make the
 *   engine unusable with such dispatchers.
 *
 * Manual triggers carry no ref, and an unrecognized trigger_type is left
 * alone: guessing at semantics we do not own would produce noise. Cron syntax
 * of a schedule ref is judged by the scheduler package's CronExpressionCheck,
 * which owns the dragonmantank/cron-expression dependency and appends itself
 * to the same check pool.
 */
class TriggerRefCheck implements CheckInterface
{
    public const CODE_TRIGGER_REF_MISSING = 'TRIGGER_REF_MISSING';
    public const CODE_TRIGGER_REF_UNKNOWN_EVENT = 'TRIGGER_REF_UNKNOWN_EVENT';

    public function __construct(
        private readonly TriggerRegistry $triggerRegistry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        $triggerType = (string) $subject->getTriggerType();
        if ($triggerType !== WorkflowInterface::TRIGGER_TYPE_EVENT
            && $triggerType !== WorkflowInterface::TRIGGER_TYPE_SCHEDULE
        ) {
            return [];
        }

        $triggerRef = trim((string) $subject->getTriggerRef());
        if ($triggerRef === '') {
            $message = $triggerType === WorkflowInterface::TRIGGER_TYPE_EVENT
                ? __('An event-triggered workflow needs a trigger reference: the async event name that starts it.')
                : __('A scheduled workflow needs a trigger reference: the cron expression that says when it runs.');

            return [ValidationMessage::error(self::CODE_TRIGGER_REF_MISSING, (string) $message)];
        }

        if ($triggerType === WorkflowInterface::TRIGGER_TYPE_EVENT
            && $this->triggerRegistry->getByEvent($triggerRef) === null
        ) {
            return [ValidationMessage::warning(
                self::CODE_TRIGGER_REF_UNKNOWN_EVENT,
                (string) __(
                    'The event "%1" is not declared in workflow_triggers.xml. The workflow will still run if '
                    . 'another module dispatches that event, but the admin cannot show its label or payload hints.',
                    $triggerRef
                )
            )];
        }

        return [];
    }
}
