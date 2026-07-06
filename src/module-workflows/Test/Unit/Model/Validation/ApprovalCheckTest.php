<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Validation;

use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Model\Validation\Check\ApprovalCheck;
use MageOS\Workflows\Model\Validation\ValidationContext;
use MageOS\Workflows\Model\Validation\ValidationSubject;
use PHPUnit\Framework\TestCase;

/**
 * Save-time approval gate checks (docs/discovery/approval-gate.md §7): the
 * module-missing backstop, the bulk-vs-required-payload conflict, and the
 * secret-in-prompt rejection.
 */
class ApprovalCheckTest extends TestCase
{
    private function approvalDefinition(array $configOverrides = []): array
    {
        return [
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => array_replace([
                        'title' => 'Approve credit',
                        'instructions' => 'Please review.',
                        'timeout' => 'P3D',
                        'payload_fields' => [
                            ['key' => 'approved_amount', 'label' => 'Amount', 'type' => 'number'],
                        ],
                    ], $configOverrides),
                    'on_approved' => 'done',
                    'on_rejected' => 'done',
                    'on_timeout' => 'done',
                ],
                'done' => ['type' => 'stop'],
            ],
        ];
    }

    private function check(array $definition, bool $moduleBound): array
    {
        $manager = $moduleBound ? new class implements ApprovalTaskManagerInterface {
            public function createTask(
                WorkflowExecutionInterface $execution,
                string $stepKey,
                string $title,
                string $instructions,
                string $dueAt,
                ?string $assigneeRole
            ): string {
                return 'uuid';
            }

            public function expireTask(int $executionId, string $stepKey): void
            {
            }

            public function orphanTasks(int $executionId): void
            {
            }
        } : null;

        return (new ApprovalCheck($manager))->check(
            new ValidationSubject((string) json_encode($definition)),
            new ValidationContext()
        );
    }

    private function codes(array $messages): array
    {
        return array_map(static fn ($m) => $m->getCode(), $messages);
    }

    public function testModuleMissingIsErrorWhenNoManagerBound(): void
    {
        $messages = $this->check($this->approvalDefinition(), false);

        $this->assertSame([ApprovalCheck::CODE_MODULE_MISSING], $this->codes($messages));
        $this->assertSame('error', $messages[0]->getSeverity());
        $this->assertSame('gate', $messages[0]->getStepKey());
    }

    public function testCleanApprovalWithManagerBoundHasNoMessages(): void
    {
        $this->assertSame([], $this->check($this->approvalDefinition(), true));
    }

    public function testBulkWithRequiredPayloadIsError(): void
    {
        $messages = $this->check($this->approvalDefinition([
            'allow_bulk' => true,
            'payload_fields' => [
                ['key' => 'approved_amount', 'label' => 'Amount', 'type' => 'number', 'required' => true],
            ],
        ]), true);

        $this->assertSame([ApprovalCheck::CODE_BULK_REQUIRED_PAYLOAD], $this->codes($messages));
    }

    public function testBulkWithoutRequiredPayloadIsClean(): void
    {
        $messages = $this->check($this->approvalDefinition([
            'allow_bulk' => true,
            'payload_fields' => [
                ['key' => 'approved_amount', 'label' => 'Amount', 'type' => 'number', 'required' => false],
            ],
        ]), true);

        $this->assertSame([], $messages);
    }

    public function testSecretInTitleIsError(): void
    {
        $messages = $this->check(
            $this->approvalDefinition(['title' => 'Use {{ secrets.api_key }} now']),
            true
        );

        $this->assertSame([ApprovalCheck::CODE_SECRET_IN_PROMPT], $this->codes($messages));
    }

    public function testSecretInInstructionsIsError(): void
    {
        $messages = $this->check(
            $this->approvalDefinition(['instructions' => 'Token: {{ secrets.token }}']),
            true
        );

        $this->assertSame([ApprovalCheck::CODE_SECRET_IN_PROMPT], $this->codes($messages));
    }

    public function testSecretWithNoSpaceAfterBracesIsError(): void
    {
        // The resolver's placeholder regex ('/\{\{\s*…/') accepts {{secrets.x}}
        // too — the check must match every form the resolver would resolve.
        $messages = $this->check(
            $this->approvalDefinition(['title' => 'Use {{secrets.api_key}} now']),
            true
        );

        $this->assertSame([ApprovalCheck::CODE_SECRET_IN_PROMPT], $this->codes($messages));
    }

    public function testSecretWithMultipleSpacesAfterBracesIsError(): void
    {
        $messages = $this->check(
            $this->approvalDefinition(['instructions' => 'Token: {{   secrets.token }}']),
            true
        );

        $this->assertSame([ApprovalCheck::CODE_SECRET_IN_PROMPT], $this->codes($messages));
    }

    public function testNonApprovalDefinitionYieldsNoMessages(): void
    {
        $messages = $this->check([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'stop']],
        ], false);

        $this->assertSame([], $messages);
    }
}
