<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsApprovals\Model\GateConfigReader;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeWorkflowExecutionRepository;
use PHPUnit\Framework\TestCase;

/**
 * The single reader behind bulk-decide, the decision panel's payload form, the
 * park notification's notify_emails, and the execution-view panel's gate check
 * (docs/discovery/approval-gate.md §6).
 */
class GateConfigReaderTest extends TestCase
{
    private const EXEC_ID = 55;

    private function reader(array $config, string $stepKey = 'gate', string $type = 'approval'): GateConfigReader
    {
        $repo = new FakeWorkflowExecutionRepository();
        $definition = (string) json_encode([
            'schema' => 4,
            'entry' => $stepKey,
            'steps' => [
                $stepKey => array_merge(['type' => $type, 'config' => $config], $type === 'approval' ? [
                    'on_approved' => 'done',
                    'on_rejected' => 'done',
                    'on_timeout' => 'done',
                ] : ['next' => 'done']),
                'done' => ['type' => 'stop'],
            ],
        ]);
        $execution = (new WorkflowExecutionStub())->setExecutionId(self::EXEC_ID)->setDefinitionSnapshot($definition);
        $repo->seed($execution);
        return new GateConfigReader($repo);
    }

    public function testGetConfigReturnsStepConfig(): void
    {
        $reader = $this->reader(['title' => 'Approve me', 'timeout' => 'P1D']);
        $this->assertSame('Approve me', $reader->getConfig(self::EXEC_ID, 'gate')['title']);
    }

    public function testGetConfigReturnsEmptyForUnknownExecution(): void
    {
        $reader = new GateConfigReader(new FakeWorkflowExecutionRepository());
        $this->assertSame([], $reader->getConfig(999, 'gate'));
    }

    public function testGetConfigReturnsEmptyForUnknownStep(): void
    {
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D']);
        $this->assertSame([], $reader->getConfig(self::EXEC_ID, 'nope'));
    }

    public function testIsBulkAllowedDefaultsFalse(): void
    {
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D']);
        $this->assertFalse($reader->isBulkAllowed(self::EXEC_ID, 'gate'));
    }

    public function testIsBulkAllowedTrueWhenDeclared(): void
    {
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D', 'allow_bulk' => true]);
        $this->assertTrue($reader->isBulkAllowed(self::EXEC_ID, 'gate'));
    }

    public function testGetPayloadFieldsNullWhenNoneDeclared(): void
    {
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D']);
        $this->assertNull($reader->getPayloadFields(self::EXEC_ID, 'gate'));
    }

    public function testGetPayloadFieldsReturnsDeclaredList(): void
    {
        $fields = [['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true]];
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D', 'payload_fields' => $fields]);
        $this->assertSame($fields, $reader->getPayloadFields(self::EXEC_ID, 'gate'));
    }

    public function testGetNotifyEmailsFiltersInvalidAddressFormat(): void
    {
        // Definition-level validation (Definition::assertApprovalStep) only requires a
        // non-empty list of non-empty strings; it does not check email-address format.
        // GateConfigReader applies the stricter FILTER_VALIDATE_EMAIL filter on top.
        $reader = $this->reader([
            'title' => 'x',
            'timeout' => 'P1D',
            'notify_emails' => ['ok@example.com', 'not-an-email'],
        ]);
        $this->assertSame(['ok@example.com'], $reader->getNotifyEmails(self::EXEC_ID, 'gate'));
    }

    public function testGetNotifyEmailsLegacyBareStringDegradesToEmpty(): void
    {
        // The authoring format is a list only (Definition::assertApprovalStep rejects a bare
        // string). A snapshot carrying the pre-fix bare-string shape now fails to parse as a
        // Definition at all, so the whole step config — not just notify_emails — degrades to
        // empty, per this reader's class-level "never throws" contract. GateConfigReader's own
        // string-tolerance in getNotifyEmails() is retained as defense in depth but is not
        // reachable through this parse path.
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D', 'notify_emails' => 'ok@example.com']);
        $this->assertSame([], $reader->getNotifyEmails(self::EXEC_ID, 'gate'));
        $this->assertSame([], $reader->getConfig(self::EXEC_ID, 'gate'));
    }

    public function testGetNotifyEmailsAbsentDegradesToEmpty(): void
    {
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D']);
        $this->assertSame([], $reader->getNotifyEmails(self::EXEC_ID, 'gate'));
    }

    public function testGetNotifyEmailsMalformedNonArrayDegradesToEmpty(): void
    {
        // Also an unparseable shape post-fix (non-list, non-string) — same full-config
        // degradation as the bare-string case above, not per-field filtering.
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D', 'notify_emails' => 12345]);
        $this->assertSame([], $reader->getNotifyEmails(self::EXEC_ID, 'gate'));
    }

    public function testIsApprovalStepTrueForApprovalType(): void
    {
        $reader = $this->reader(['title' => 'x', 'timeout' => 'P1D']);
        $this->assertTrue($reader->isApprovalStep(self::EXEC_ID, 'gate'));
    }

    public function testIsApprovalStepFalseForOtherType(): void
    {
        $reader = $this->reader(['duration' => 'P1D'], 'gate', 'delay');
        $this->assertFalse($reader->isApprovalStep(self::EXEC_ID, 'gate'));
    }

    public function testIsApprovalStepFalseWhenUnresolvable(): void
    {
        $reader = new GateConfigReader(new FakeWorkflowExecutionRepository());
        $this->assertFalse($reader->isApprovalStep(999, 'gate'));
    }
}
