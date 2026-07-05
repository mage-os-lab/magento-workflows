<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\BulkDecideFilter;
use MageOS\WorkflowsApprovals\Model\GateConfigReader;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeApproval;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeWorkflowExecutionRepository;
use PHPUnit\Framework\TestCase;

/**
 * The allow_bulk gate for mass-decide (docs/discovery/approval-gate.md §6):
 * server-side enforcement per row, never just a grid filter.
 */
class BulkDecideFilterTest extends TestCase
{
    private const EXEC_ID = 77;

    private function filter(bool $allowBulk): BulkDecideFilter
    {
        $repo = new FakeWorkflowExecutionRepository();
        $definition = (string) json_encode([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => ['title' => 'x', 'timeout' => 'P1D', 'allow_bulk' => $allowBulk],
                    'on_approved' => 'done',
                    'on_rejected' => 'done',
                    'on_timeout' => 'done',
                ],
                'done' => ['type' => 'stop'],
            ],
        ]);
        $repo->seed((new WorkflowExecutionStub())->setExecutionId(self::EXEC_ID)->setDefinitionSnapshot($definition));
        return new BulkDecideFilter(new GateConfigReader($repo));
    }

    private function task(string $status): ApprovalInterface
    {
        return (new FakeApproval())->setExecutionId(self::EXEC_ID)->setStepKey('gate')->setStatus($status);
    }

    public function testEligibleWhenOpenAndBulkAllowed(): void
    {
        $this->assertTrue($this->filter(true)->isEligible($this->task(ApprovalInterface::STATUS_OPEN)));
    }

    public function testIneligibleWhenBulkNotAllowed(): void
    {
        $this->assertFalse($this->filter(false)->isEligible($this->task(ApprovalInterface::STATUS_OPEN)));
    }

    public function testIneligibleWhenNotOpenEvenIfBulkAllowed(): void
    {
        $this->assertFalse($this->filter(true)->isEligible($this->task(ApprovalInterface::STATUS_APPROVED)));
    }
}
