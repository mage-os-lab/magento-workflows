<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Model\Validation;

use Cron\CronExpression;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Validation\Check\CheckInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;

/**
 * Save-time cron syntax for schedule-type workflows (F2). It lives in the
 * scheduler package — not the engine — because the parse authority is
 * dragonmantank/cron-expression, this package's composer dependency and the
 * exact library RunScheduledWorkflows evaluates trigger_ref with at runtime;
 * the engine must not gain that dependency. Registration therefore happens
 * from this module's di.xml, appending to the engine's ordered check pool the
 * same way domain packs append hydrators and option sources.
 *
 * Before this check an unparseable expression saved cleanly and the workflow
 * simply never fired: the only trace was an error line in the cron log
 * (RunScheduledWorkflows::evaluate). Now the author is told at save time,
 * quoting the library's own reason.
 *
 * Non-schedule trigger types are none of this check's business (an event ref
 * is judged by the engine's TriggerRefCheck).
 */
class CronExpressionCheck implements CheckInterface
{
    public const CODE_CRON_INVALID = 'TRIGGER_REF_INVALID_CRON';

    /**
     * @inheritDoc
     */
    public function check(ValidationSubject $subject, ValidationContext $context): array
    {
        if ($subject->getTriggerType() !== WorkflowInterface::TRIGGER_TYPE_SCHEDULE) {
            return [];
        }

        $triggerRef = trim((string) $subject->getTriggerRef());
        if ($triggerRef === '') {
            // An empty ref is TriggerRefCheck's error to report; do not
            // double up on the same field.
            return [];
        }

        try {
            new CronExpression($triggerRef);
        } catch (\Throwable $e) {
            return [ValidationMessage::error(
                self::CODE_CRON_INVALID,
                (string) __(
                    'The schedule "%1" is not a valid cron expression: %2',
                    $triggerRef,
                    $e->getMessage()
                )
            )];
        }

        return [];
    }
}
