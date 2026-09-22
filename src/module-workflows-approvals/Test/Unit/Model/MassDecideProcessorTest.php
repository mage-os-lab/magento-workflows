<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\ApprovalService;
use MageOS\WorkflowsApprovals\Model\BulkDecideFilter;
use MageOS\WorkflowsApprovals\Model\DecisionPayloadValidator;
use MageOS\WorkflowsApprovals\Model\Exception\MassDecideCapExceededException;
use MageOS\WorkflowsApprovals\Model\GateConfigReader;
use MageOS\WorkflowsApprovals\Model\MassDecideProcessor;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeApproval;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeApprovalAuthorization;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeWorkflowExecutionRepository;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\InMemoryConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\RecordingPublisher;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The mass-decide loop (docs/discovery/approval-gate.md §6): the allow_bulk
 * gate is enforced per row (never just a grid filter), every outcome is
 * counted and reported, and each row goes through the exact same
 * ApprovalService::decide() as REST/the decision view.
 */
class MassDecideProcessorTest extends TestCase
{
    private InMemoryConnection $connection;
    private RecordingPublisher $publisher;
    private FakeWorkflowExecutionRepository $executionRepository;

    public function setUp(): void
    {
        $this->connection = new InMemoryConnection();
        $this->publisher = new RecordingPublisher();
        $this->executionRepository = new FakeWorkflowExecutionRepository();
    }

    private function seedGate(int $executionId, string $stepKey, bool $allowBulk, string $taskStatus, string $uuid): FakeApproval
    {
        $definition = (string) json_encode([
            'schema' => 4,
            'entry' => $stepKey,
            'steps' => [
                $stepKey => [
                    'type' => 'approval',
                    'config' => ['title' => 'x', 'timeout' => 'P1D', 'allow_bulk' => $allowBulk],
                    'on_approved' => 'done',
                    'on_rejected' => 'done',
                    'on_timeout' => 'done',
                ],
                'done' => ['type' => 'stop'],
            ],
        ]);
        $this->executionRepository->seed(
            (new WorkflowExecutionStub())->setExecutionId($executionId)->setDefinitionSnapshot($definition)
        );
        $this->connection->seedExecution([
            'execution_id' => $executionId,
            'status' => 'waiting',
            'current_step' => $stepKey,
            'definition_snapshot' => $definition,
        ]);
        $this->connection->seedApproval([
            'uuid' => $uuid,
            'execution_id' => $executionId,
            'step_key' => $stepKey,
            'status' => $taskStatus,
            'assignee_role' => null,
        ]);
        return (new FakeApproval())->setUuid($uuid)->setExecutionId($executionId)->setStepKey($stepKey)->setStatus($taskStatus);
    }

    private function processor(bool $authorized = true): MassDecideProcessor
    {
        $approvalService = new ApprovalService(
            new FakeResourceConnection($this->connection),
            $this->publisher,
            new DecisionPayloadValidator(),
            new FakeApprovalAuthorization($authorized),
            new NullLogger()
        );
        $bulkDecideFilter = new BulkDecideFilter(new GateConfigReader($this->executionRepository));
        return new MassDecideProcessor($approvalService, $bulkDecideFilter, new NullLogger());
    }

    public function testMixedSelectionCountsEachOutcome(): void
    {
        $bulkTask = $this->seedGate(1, 'gate', true, ApprovalInterface::STATUS_OPEN, 'u-bulk');
        $notBulkTask = $this->seedGate(2, 'gate', false, ApprovalInterface::STATUS_OPEN, 'u-not-bulk');
        $decidedTask = $this->seedGate(3, 'gate', true, ApprovalInterface::STATUS_APPROVED, 'u-decided');

        $result = $this->processor()->process(
            [$bulkTask, $notBulkTask, $decidedTask],
            'approved',
            'batch note',
            'admin',
            '5',
            200
        );

        $this->assertSame(1, $result->getDecided());
        $this->assertSame(1, $result->getSkippedNotBulk());
        $this->assertSame(1, $result->getAlreadyDecided());
        $this->assertSame(0, $result->getFailed());
        $this->assertSame('approved', $this->connection->approvals[1]['status']);
        // The ineligible rows were never touched.
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $this->connection->approvals[2]['status']);
    }

    public function testCapExceededThrowsBeforeAnyRowIsTouched(): void
    {
        $task = $this->seedGate(1, 'gate', true, ApprovalInterface::STATUS_OPEN, 'u-1');

        try {
            $this->processor()->process([$task], 'approved', null, 'admin', '5', 0);
            $this->fail('Expected the cap to be exceeded');
        } catch (MassDecideCapExceededException $e) {
            $this->assertSame(1, $e->getSelected());
            $this->assertSame(0, $e->getCap());
        }

        $this->assertSame(ApprovalInterface::STATUS_OPEN, $this->connection->approvals[1]['status']);
    }

    public function testRaceLostBetweenGridLoadAndSubmitCountsAsAlreadyDecided(): void
    {
        // The FakeApproval snapshot says open+bulk-enabled (as of grid load),
        // but the underlying row was already decided by someone else since.
        $task = $this->seedGate(1, 'gate', true, ApprovalInterface::STATUS_OPEN, 'u-1');
        $this->connection->approvals[1]['status'] = ApprovalInterface::STATUS_REJECTED;

        $result = $this->processor()->process([$task], 'approved', null, 'admin', '5', 200);

        $this->assertSame(0, $result->getDecided());
        $this->assertSame(1, $result->getAlreadyDecided());
        $this->assertSame(0, $result->getFailed());
    }

    public function testPublishFailureCountsAsFailedNotDecided(): void
    {
        $task = $this->seedGate(1, 'gate', true, ApprovalInterface::STATUS_OPEN, 'u-1');
        $this->publisher->throwOnPublish = true;

        $result = $this->processor()->process([$task], 'approved', null, 'admin', '5', 200);

        $this->assertSame(0, $result->getDecided());
        $this->assertSame(1, $result->getFailed());
        $this->assertSame(0, $result->getAlreadyDecided());
    }

    public function testToMessageNamesEveryOutcome(): void
    {
        $bulkTask = $this->seedGate(1, 'gate', true, ApprovalInterface::STATUS_OPEN, 'u-bulk');
        $notBulkTask = $this->seedGate(2, 'gate', false, ApprovalInterface::STATUS_OPEN, 'u-not-bulk');

        $result = $this->processor()->process([$bulkTask, $notBulkTask], 'approved', null, 'admin', '5', 200);

        $this->assertSame('1 decided, 1 skipped (not bulk-enabled).', $result->toMessage());
    }
}
