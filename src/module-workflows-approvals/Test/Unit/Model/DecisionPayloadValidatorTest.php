<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use MageOS\WorkflowsApprovals\Model\DecisionPayloadValidator;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;
use PHPUnit\Framework\TestCase;

/**
 * The §5 payload matrix: envelope caps, opt-in acceptance, allowlisting, type
 * coercion per declared type, non-coercible rejection, and required-on-approve.
 */
class DecisionPayloadValidatorTest extends TestCase
{
    private DecisionPayloadValidator $validator;

    public function setUp(): void
    {
        $this->validator = new DecisionPayloadValidator();
    }

    /**
     * @param array<int, array> $declaration
     */
    private function assertCode(string $expectedCode, array $payload, ?array $declaration, bool $isApproved): void
    {
        try {
            $this->validator->validate($payload, $declaration, $isApproved);
            $this->fail('Expected ApprovalDecisionException ' . $expectedCode);
        } catch (ApprovalDecisionException $e) {
            $this->assertSame($expectedCode, $e->getApprovalCode());
        }
    }

    private function number(bool $required = false): array
    {
        return [['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => $required]];
    }

    public function testNoDeclarationRejectsNonEmptyPayload(): void
    {
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_NOT_ACCEPTED, ['x' => 1], null, false);
    }

    public function testNoDeclarationAcceptsEmptyPayload(): void
    {
        $this->assertSame([], $this->validator->validate([], null, true));
    }

    public function testUnknownKeyRejected(): void
    {
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_UNKNOWN_KEY, ['other' => 1], $this->number(), false);
    }

    public function testStringCoercion(): void
    {
        $decl = [['key' => 'label', 'label' => 'L', 'type' => 'string']];
        $this->assertSame(['label' => '5'], $this->validator->validate(['label' => 5], $decl, true));
        $this->assertSame(['label' => 'true'], $this->validator->validate(['label' => true], $decl, true));
    }

    public function testNumberCoercionFromString(): void
    {
        $this->assertSame(['amount' => 100], $this->validator->validate(['amount' => '100'], $this->number(), true));
        $this->assertSame(['amount' => 2.5], $this->validator->validate(['amount' => '2.5'], $this->number(), true));
    }

    public function testBooleanCoercion(): void
    {
        $decl = [['key' => 'flag', 'label' => 'F', 'type' => 'boolean']];
        $this->assertSame(['flag' => true], $this->validator->validate(['flag' => 'true'], $decl, true));
        $this->assertSame(['flag' => false], $this->validator->validate(['flag' => 0], $decl, true));
    }

    public function testNonCoercibleNumberRejected(): void
    {
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_TYPE_MISMATCH, ['amount' => 'abc'], $this->number(), true);
        // A boolean is not a number (is_numeric(true) is false).
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_TYPE_MISMATCH, ['amount' => true], $this->number(), true);
    }

    public function testRequiredEnforcedOnApproveOnly(): void
    {
        // Missing required field is rejected on approve...
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_REQUIRED_MISSING, [], $this->number(true), true);
        // ...but not on reject.
        $this->assertSame([], $this->validator->validate([], $this->number(true), false));
    }

    public function testKeyCountCap(): void
    {
        $payload = [];
        for ($i = 0; $i <= DecisionPayloadValidator::MAX_KEYS; $i++) {
            $payload['k' . $i] = 1;
        }
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_TOO_MANY_KEYS, $payload, null, false);
    }

    public function testSizeCap(): void
    {
        $payload = ['blob' => str_repeat('a', DecisionPayloadValidator::MAX_BYTES + 1)];
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_TOO_LARGE, $payload, null, false);
    }

    public function testNonScalarValueRejected(): void
    {
        $this->assertCode(ApprovalDecisionException::CODE_PAYLOAD_NOT_FLAT, ['x' => ['nested' => 1]], null, false);
    }

    public function testNoteLengthCap(): void
    {
        // Under and at the cap pass silently.
        $this->validator->validateNote(null);
        $this->validator->validateNote(str_repeat('a', DecisionPayloadValidator::MAX_NOTE_LENGTH));

        try {
            $this->validator->validateNote(str_repeat('a', DecisionPayloadValidator::MAX_NOTE_LENGTH + 1));
            $this->fail('Expected note-too-long rejection');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_NOTE_TOO_LONG, $e->getApprovalCode());
        }
    }
}
