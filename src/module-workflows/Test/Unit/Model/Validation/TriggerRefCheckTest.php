<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\Trigger\TriggerRegistry;
use MageOS\Workflows\Model\Validation\Check\TriggerRefCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationMessage;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use PHPUnit\Framework\TestCase;

/**
 * Save-time trigger-reference sanity: the missing-ref error for the two
 * trigger types that need one, and the deliberately soft treatment of an
 * event the registry does not describe (third-party dispatchers).
 */
class TriggerRefCheckTest extends TestCase
{
    private function triggerRegistry(string $knownEvent = 'customer.group_changed'): TriggerRegistry
    {
        return new class ($knownEvent) extends TriggerRegistry {
            public function __construct(private readonly string $event)
            {
            }

            public function getByEvent(string $event): ?array
            {
                return $event === $this->event
                    ? ['event' => $this->event, 'entity' => 'customer', 'label' => 'Customer Group Changed']
                    : null;
            }

            public function getAll(): array
            {
                return [$this->event => ['event' => $this->event, 'entity' => 'customer', 'label' => 'X']];
            }
        };
    }

    private function check(): TriggerRefCheck
    {
        return new TriggerRefCheck($this->triggerRegistry());
    }

    private function subject(string $triggerType, string $triggerRef): ValidationSubject
    {
        return new ValidationSubject(
            '{"schema":3,"steps":[],"entry":null}',
            null,
            $triggerType,
            $triggerRef,
            'customer'
        );
    }

    public function testManualTriggerIsNotJudged(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_MANUAL, '');
        $this->assertSame([], $this->check()->check($subject, new ValidationContext()));
    }

    public function testUnknownTriggerTypeIsNotJudged(): void
    {
        // Guessing at semantics this module does not own would only add noise.
        $subject = $this->subject('webhook', '');
        $this->assertSame([], $this->check()->check($subject, new ValidationContext()));
    }

    public function testEventTriggerWithoutARefIsAnError(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_EVENT, '');
        $messages = $this->check()->check($subject, new ValidationContext());

        $this->assertCount(1, $messages);
        $this->assertSame(TriggerRefCheck::CODE_TRIGGER_REF_MISSING, $messages[0]->getCode());
        $this->assertSame(ValidationMessage::SEVERITY_ERROR, $messages[0]->getSeverity());
    }

    public function testScheduleTriggerWithABlankRefIsAnError(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, '   ');
        $messages = $this->check()->check($subject, new ValidationContext());

        $this->assertCount(1, $messages);
        $this->assertSame(TriggerRefCheck::CODE_TRIGGER_REF_MISSING, $messages[0]->getCode());
        $this->assertSame(ValidationMessage::SEVERITY_ERROR, $messages[0]->getSeverity());
    }

    public function testDeclaredEventPasses(): void
    {
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_EVENT, 'customer.group_changed');
        $this->assertSame([], $this->check()->check($subject, new ValidationContext()));
    }

    public function testUndeclaredEventOnlyWarns(): void
    {
        // workflow_triggers.xml is UI metadata, not the dispatch authority: a
        // third-party module may publish an event it never declared, and
        // blocking that save would make the engine unusable with it.
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_EVENT, 'thirdparty.thing_happened');
        $messages = $this->check()->check($subject, new ValidationContext());

        $this->assertCount(1, $messages);
        $this->assertSame(TriggerRefCheck::CODE_TRIGGER_REF_UNKNOWN_EVENT, $messages[0]->getCode());
        $this->assertSame(ValidationMessage::SEVERITY_WARNING, $messages[0]->getSeverity());
        $this->assertStringContainsString('thirdparty.thing_happened', $messages[0]->getMessage());
    }

    public function testScheduleRefIsNotMatchedAgainstTheEventRegistry(): void
    {
        // A cron expression is never a registry event; its syntax is the
        // scheduler package's CronExpressionCheck to judge.
        $subject = $this->subject(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, '0 3 * * *');
        $this->assertSame([], $this->check()->check($subject, new ValidationContext()));
    }
}
