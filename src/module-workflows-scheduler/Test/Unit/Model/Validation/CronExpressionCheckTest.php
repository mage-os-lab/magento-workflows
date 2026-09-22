<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Model\Validation;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\WorkflowsScheduler\Model\Validation\CronExpressionCheck;
use PHPUnit\Framework\TestCase;

// The standalone runner's shim autoloader only serves Magento\ / Psr\Log\
// classes; dragonmantank/cron-expression's Cron\CronExpression is shimmed
// under dev/tests/shims/Cron/ and loaded here explicitly. Real Composer
// environments (real library installed) never reach the require.
if (!class_exists(\Cron\CronExpression::class)) {
    require_once dirname(__DIR__, 6) . '/dev/tests/shims/Cron/CronExpression.php';
}

/**
 * Save-time cron syntax: an expression the scheduler could never evaluate is
 * refused at save instead of failing silently in a cron log at 3am.
 */
class CronExpressionCheckTest extends TestCase
{
    private function subject(string $triggerType, string $triggerRef): ValidationSubject
    {
        return new ValidationSubject(
            '{"schema":3,"steps":[],"entry":null}',
            null,
            $triggerType,
            $triggerRef,
            'sales_order'
        );
    }

    public function testValidFiveFieldExpressionPasses(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, '0 3 * * *');
        $this->assertSame([], (new CronExpressionCheck())->check($subject, new ValidationContext()));
    }

    public function testUnparseableExpressionIsAnError(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, 'every tuesday-ish');
        $messages = (new CronExpressionCheck())->check($subject, new ValidationContext());

        $this->assertCount(1, $messages);
        $this->assertSame(CronExpressionCheck::CODE_CRON_INVALID, $messages[0]->getCode());
        $this->assertSame(ValidationMessage::SEVERITY_ERROR, $messages[0]->getSeverity());
        $this->assertStringContainsString('every tuesday-ish', $messages[0]->getMessage());
    }

    public function testOutOfRangeFieldIsAnError(): void
    {
        // 61 minutes past the hour parses as a token but not as a minute.
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, '61 3 * * *');
        $messages = (new CronExpressionCheck())->check($subject, new ValidationContext());

        $this->assertCount(1, $messages);
        $this->assertSame(CronExpressionCheck::CODE_CRON_INVALID, $messages[0]->getCode());
    }

    public function testEventTriggerIsNoOp(): void
    {
        // An event ref is never a cron expression; TriggerRefCheck owns it.
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_EVENT, 'sales.order.created');
        $this->assertSame([], (new CronExpressionCheck())->check($subject, new ValidationContext()));
    }

    public function testEmptyScheduleRefIsLeftToTriggerRefCheck(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, '');
        $this->assertSame([], (new CronExpressionCheck())->check($subject, new ValidationContext()));
    }
}
