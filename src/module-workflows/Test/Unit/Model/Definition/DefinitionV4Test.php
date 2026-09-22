<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Definition;

use MageOS\Workflows\Model\Definition\Definition;
use PHPUnit\Framework\TestCase;

/**
 * Schema 4 (approval gate): shape validation matrix and the three-edge
 * getStepEdges routing. Mirrors DefinitionV3Test's structure.
 */
class DefinitionV4Test extends TestCase
{
    private function approvalDefinition(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => Definition::STEP_APPROVAL,
                    'config' => [
                        'title' => 'Approve credit for {{ trigger.increment_id }}',
                        'instructions' => 'Please review.',
                        'timeout' => 'P3D',
                        'assignee_role' => 'sales_managers',
                        'allow_bulk' => false,
                        'payload_fields' => [
                            ['key' => 'approved_amount', 'label' => 'Amount', 'type' => 'number', 'required' => false],
                        ],
                    ],
                    'on_approved' => 'approved',
                    'on_rejected' => 'rejected',
                    'on_timeout' => 'timed_out',
                ],
                'approved' => ['type' => Definition::STEP_STOP],
                'rejected' => ['type' => Definition::STEP_STOP],
                'timed_out' => ['type' => Definition::STEP_STOP],
            ],
        ], $overrides);
    }

    public function testSchemaFourAcceptedAndReturned(): void
    {
        $definition = Definition::fromArray($this->approvalDefinition());

        $this->assertSame(4, $definition->getSchemaVersion());
        $this->assertSame(Definition::STEP_APPROVAL, $definition->getStep('gate')['type']);
    }

    public function testApprovalStepAcceptedUnderLegacySchema3(): void
    {
        // Legacy schema numbers normalize to the current version on parse;
        // step types are no longer version-gated.
        $definition = Definition::fromArray($this->approvalDefinition(['schema' => 3]));

        $this->assertSame(Definition::STEP_APPROVAL, $definition->getStep('gate')['type']);
        $this->assertSame(Definition::SCHEMA_VERSION, $definition->getSchemaVersion());
    }

    public function testMissingTitleRejected(): void
    {
        $data = $this->approvalDefinition();
        unset($data['steps']['gate']['config']['title']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing a valid config.title');

        Definition::fromArray($data);
    }

    public function testEmptyTitleRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['title'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing a valid config.title');

        Definition::fromArray($data);
    }

    public function testMissingTimeoutRejected(): void
    {
        $data = $this->approvalDefinition();
        unset($data['steps']['gate']['config']['timeout']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.timeout');

        Definition::fromArray($data);
    }

    public function testNonIso8601TimeoutRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['timeout'] = '3 days';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not ISO-8601');

        Definition::fromArray($data);
    }

    public function testInstructionsOptional(): void
    {
        $data = $this->approvalDefinition();
        unset($data['steps']['gate']['config']['instructions']);

        $definition = Definition::fromArray($data);
        $this->assertTrue($definition->hasStep('gate'));
    }

    public function testNonStringInstructionsRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['instructions'] = ['bad'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config.instructions must be a string');

        Definition::fromArray($data);
    }

    public function testEmptyAssigneeRoleRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['assignee_role'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assignee_role must be a non-empty string');

        Definition::fromArray($data);
    }

    public function testNonBooleanAllowBulkRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['allow_bulk'] = 'yes';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allow_bulk must be boolean');

        Definition::fromArray($data);
    }

    public function testPayloadFieldsOptional(): void
    {
        $data = $this->approvalDefinition();
        unset($data['steps']['gate']['config']['payload_fields']);

        $definition = Definition::fromArray($data);
        $this->assertTrue($definition->hasStep('gate'));
    }

    public function testEmptyPayloadFieldsListRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['payload_fields'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload_fields must be a non-empty list');

        Definition::fromArray($data);
    }

    public function testPayloadFieldInvalidKeyRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['payload_fields'][0]['key'] = 'bad key!';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid "key"');

        Definition::fromArray($data);
    }

    public function testPayloadFieldDuplicateKeysRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['payload_fields'][] =
            ['key' => 'approved_amount', 'label' => 'Again', 'type' => 'string'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate payload_fields key "approved_amount"');

        Definition::fromArray($data);
    }

    public function testPayloadFieldMissingLabelRejected(): void
    {
        $data = $this->approvalDefinition();
        unset($data['steps']['gate']['config']['payload_fields'][0]['label']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is missing a "label"');

        Definition::fromArray($data);
    }

    public function testPayloadFieldInvalidTypeRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['payload_fields'][0]['type'] = 'date';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of string|number|boolean');

        Definition::fromArray($data);
    }

    public function testNotifyEmailsOptional(): void
    {
        $data = $this->approvalDefinition();
        unset($data['steps']['gate']['config']['notify_emails']);

        $definition = Definition::fromArray($data);
        $this->assertTrue($definition->hasStep('gate'));
    }

    public function testNotifyEmailsValidListAccepted(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['notify_emails'] = ['sales-managers@example.com', 'ops@example.com'];

        $definition = Definition::fromArray($data);
        $this->assertSame(
            ['sales-managers@example.com', 'ops@example.com'],
            $definition->getStep('gate')['config']['notify_emails']
        );
    }

    public function testNotifyEmailsNonListRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['notify_emails'] = 'sales-managers@example.com';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('notify_emails must be a non-empty list');

        Definition::fromArray($data);
    }

    public function testNotifyEmailsEmptyListRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['notify_emails'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('notify_emails must be a non-empty list');

        Definition::fromArray($data);
    }

    public function testNotifyEmailsNonStringEntryRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['config']['notify_emails'] = ['ok@example.com', 42];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('notify_emails #1 must be a non-empty string');

        Definition::fromArray($data);
    }

    public function testDanglingApprovedEdgeRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['on_approved'] = 'nowhere';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('edge "on_approved" points to unknown step "nowhere"');

        Definition::fromArray($data);
    }

    public function testDanglingRejectedEdgeRejected(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['on_rejected'] = 'nowhere';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('edge "on_rejected" points to unknown step "nowhere"');

        Definition::fromArray($data);
    }

    public function testNullTimeoutEdgeAccepted(): void
    {
        $data = $this->approvalDefinition();
        $data['steps']['gate']['on_timeout'] = null;

        $definition = Definition::fromArray($data);
        $this->assertNull($definition->getStepEdges('gate')['on_timeout']);
    }

    public function testGetStepEdgesReturnsThreeNamedEdges(): void
    {
        $definition = Definition::fromArray($this->approvalDefinition());

        $this->assertSame(
            ['on_approved' => 'approved', 'on_rejected' => 'rejected', 'on_timeout' => 'timed_out'],
            $definition->getStepEdges('gate')
        );
    }

    public function testFixtureIsValid(): void
    {
        $path = dirname(__DIR__, 6) . '/spec/fixtures/goodwill-credit-approval.json';
        $data = json_decode((string) file_get_contents($path), true);

        $definition = Definition::fromArray($data['definition']);
        $this->assertSame(4, $definition->getSchemaVersion());
        $this->assertSame(
            ['on_approved' => 'issue_credit', 'on_rejected' => 'policy_email', 'on_timeout' => 'escalate'],
            $definition->getStepEdges('gate')
        );
    }
}
