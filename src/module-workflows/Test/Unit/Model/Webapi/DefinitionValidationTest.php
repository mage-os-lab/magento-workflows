<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Webapi;

use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Model\PlainLanguageRenderer;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationResult;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use MageOS\Workflows\Model\Validation\WorkflowValidator;
use MageOS\Workflows\Model\Webapi\DefinitionValidation;
use PHPUnit\Framework\TestCase;

/**
 * POST /V1/workflows/validate (F6) — also the service the canvas and the
 * admin preview/conditions controllers call. The trigger fields the endpoint
 * accepts must reach the ValidationSubject, not only the plain-language
 * renderer: otherwise the trigger-aware checks stay silent here and the
 * endpoint disagrees with what the same pipeline says at save time.
 */
class DefinitionValidationTest extends TestCase
{
    private const DEFINITION = '{"schema":3,"entry":"s1","steps":{"s1":{"type":"stop"}}}';

    private function service(CapturingValidator $validator): DefinitionValidation
    {
        $service = (new \ReflectionClass(DefinitionValidation::class))->newInstanceWithoutConstructor();
        foreach (['validator' => $validator, 'plainLanguageRenderer' => new SilentRenderer()] as $name => $value) {
            (new \ReflectionProperty($service, $name))->setValue($service, $value);
        }

        return $service;
    }

    public function testTriggerFieldsReachTheValidationSubject(): void
    {
        $validator = new CapturingValidator();
        $this->service($validator)->validate(
            self::DEFINITION,
            '{"type":"root"}',
            WorkflowInterface::TRIGGER_TYPE_SCHEDULE,
            '0 3 * * *',
            'sales_order'
        );

        $this->assertNotNull($validator->subject);
        $this->assertSame(WorkflowInterface::TRIGGER_TYPE_SCHEDULE, $validator->subject->getTriggerType());
        $this->assertSame('0 3 * * *', $validator->subject->getTriggerRef());
        $this->assertSame('sales_order', $validator->subject->getEntityType());
        $this->assertSame('{"type":"root"}', $validator->subject->getConditionsSerialized());
    }

    public function testOmittedTriggerFieldsStayNull(): void
    {
        // Definition-only callers (dry-run previews) must not acquire phantom
        // trigger data that the trigger checks would then judge.
        $validator = new CapturingValidator();
        $this->service($validator)->validate(self::DEFINITION);

        $this->assertNotNull($validator->subject);
        $this->assertNull($validator->subject->getTriggerType());
        $this->assertNull($validator->subject->getTriggerRef());
        $this->assertNull($validator->subject->getEntityType());
    }

    public function testFindingsAreReportedRatherThanThrown(): void
    {
        $validator = new CapturingValidator(new ValidationResult([
            \MageOS\Workflows\Model\Validation\ValidationMessage::error('TRIGGER_REF_MISSING', 'no ref'),
        ]));

        $result = $this->service($validator)->validate(self::DEFINITION, null, 'event', '');

        $this->assertFalse($result->getValid());
        $this->assertCount(1, $result->getMessages());
    }

    public function testValidationRunsInDryRunMode(): void
    {
        // Per-action ACL re-authorization is an authoring-time gate (F2).
        $validator = new CapturingValidator();
        $this->service($validator)->validate(self::DEFINITION);

        $this->assertNotNull($validator->context);
        $this->assertTrue($validator->context->isDryRun());
    }
}

/**
 * Captures the subject and context the endpoint builds, returning a scripted
 * result instead of running the check pool.
 */
class CapturingValidator extends WorkflowValidator
{
    public ?ValidationSubject $subject = null;

    public ?ValidationContext $context = null;

    private ValidationResult $scripted;

    // Bypass the parent constructor (it wants the check chain).
    public function __construct(?ValidationResult $scripted = null)
    {
        $this->scripted = $scripted ?? new ValidationResult([]);
    }

    public function validate(ValidationSubject $subject, ValidationContext $context): ValidationResult
    {
        $this->subject = $subject;
        $this->context = $context;
        return $this->scripted;
    }
}

/**
 * The sentence is not what this suite is about; the renderer needs a whole
 * action/relation/trigger pool to build one.
 */
class SilentRenderer extends PlainLanguageRenderer
{
    public function __construct()
    {
    }

    public function renderFromFields(
        string $triggerType,
        string $triggerRef,
        string $entityType,
        ?string $conditionsSerialized,
        string $definitionJson,
        ?string $fanOut = null,
        ?string $aggregationJson = null
    ): string {
        return '';
    }
}
