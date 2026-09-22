<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\ApprovalService;
use MageOS\WorkflowsApprovals\Model\DecisionPayloadValidator;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\CallLog;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeApprovalAuthorization;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\FakeResourceConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\InMemoryConnection;
use MageOS\WorkflowsApprovals\Test\Unit\Stub\RecordingPublisher;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * The decision path (§4): success (result-before-publish + both claims),
 * validation-before-claim ordering, and every race — decision-vs-decision,
 * decision-vs-timeout, publish-failure rollback — plus role enforcement.
 */
class ApprovalServiceTest extends TestCase
{
    private const UUID = 'task-uuid-1';
    private const EXEC_ID = 202;

    private InMemoryConnection $connection;
    private RecordingPublisher $publisher;
    private CallLog $log;

    public function setUp(): void
    {
        $this->log = new CallLog();
        $this->connection = new InMemoryConnection();
        $this->connection->log = $this->log;
        $this->publisher = new RecordingPublisher($this->log);
    }

    private function service(bool $allowRole = true): ApprovalService
    {
        return new ApprovalService(
            new FakeResourceConnection($this->connection),
            $this->publisher,
            new DecisionPayloadValidator(),
            new FakeApprovalAuthorization($allowRole),
            new NullLogger()
        );
    }

    /**
     * @param array<int, array>|null $payloadFields
     */
    private function seed(?array $payloadFields = null, ?string $assigneeRole = null, string $taskStatus = ApprovalInterface::STATUS_OPEN): int
    {
        $config = ['title' => 'Approve', 'timeout' => 'P1D'];
        if ($payloadFields !== null) {
            $config['payload_fields'] = $payloadFields;
        }
        $definition = (string) json_encode([
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => $config,
                    'on_approved' => 'done',
                    'on_rejected' => 'done',
                    'on_timeout' => 'done',
                ],
                'done' => ['type' => 'stop'],
            ],
        ]);
        $this->connection->seedExecution([
            'execution_id' => self::EXEC_ID,
            'status' => 'waiting',
            'current_step' => 'gate',
            'definition_snapshot' => $definition,
        ]);
        return $this->connection->seedApproval([
            'uuid' => self::UUID,
            'execution_id' => self::EXEC_ID,
            'step_key' => 'gate',
            'status' => $taskStatus,
            'assignee_role' => $assigneeRole,
        ]);
    }

    public function testSuccessWritesResultBeforePublishAndClaimsBoth(): void
    {
        $id = $this->seed();

        $result = $this->service()->decide(self::UUID, 'approved', 'Looks good', [], 'admin', '5');

        $this->assertSame(self::UUID, $result->getUuid());
        $this->assertSame('approved', $result->getStatus());
        $this->assertSame(self::EXEC_ID, $result->getExecutionId());

        $row = $this->connection->approvals[$id];
        $this->assertSame('approved', $row['status']);
        $this->assertSame('admin', $row[ApprovalInterface::DECIDED_BY_TYPE]);
        $this->assertSame('5', $row[ApprovalInterface::DECIDED_BY_ID]);
        $this->assertSame('Looks good', $row[ApprovalInterface::DECISION_NOTE]);
        $this->assertNotNull($row[ApprovalInterface::DECIDED_AT]);

        $this->assertSame('pending', $this->connection->executions[self::EXEC_ID]['status']);

        $stepResult = json_decode((string) $this->connection->stepResults[self::EXEC_ID], true);
        $this->assertSame('approved', $stepResult['resolution']);
        $this->assertSame('admin:5', $stepResult['decided_by']);
        $this->assertSame('Looks good', $stepResult['note']);

        $this->assertSame([['mageos.workflow.resume', '202']], $this->publisher->published);
        // The parked step row carried the decision BEFORE the resume publish.
        $this->assertSame(['result:write', 'publish'], $this->log->entries);
    }

    public function testApprovedPayloadFlowsIntoStepResult(): void
    {
        $this->seed([['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true]]);

        $this->service()->decide(self::UUID, 'approved', null, ['amount' => '100'], 'integration', '9');

        $stepResult = json_decode((string) $this->connection->stepResults[self::EXEC_ID], true);
        $this->assertSame(['amount' => 100], $stepResult['payload']);
        $this->assertSame('integration:9', $stepResult['decided_by']);
    }

    public function testInvalidDecisionRejectedBeforeAnyClaim(): void
    {
        $this->seed();
        try {
            $this->service()->decide(self::UUID, 'maybe', null, [], 'admin', '5');
            $this->fail('Expected invalid-decision rejection');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_INVALID_DECISION, $e->getApprovalCode());
        }
        $this->assertSame([], $this->connection->updates);
    }

    public function testUnknownTaskThrowsNoSuchEntity(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->service()->decide('nope', 'approved', null, [], 'admin', '5');
    }

    public function testPayloadRejectedBeforeAnyClaim(): void
    {
        // Gate declares no payload_fields, so a non-empty payload is rejected
        // and no task/execution row is ever touched.
        $this->seed();
        try {
            $this->service()->decide(self::UUID, 'approved', null, ['x' => 1], 'admin', '5');
            $this->fail('Expected payload rejection');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_PAYLOAD_NOT_ACCEPTED, $e->getApprovalCode());
        }
        $this->assertSame([], $this->connection->updates);
    }

    public function testRequiredPayloadEnforcedOnApproveNotReject(): void
    {
        $id = $this->seed([['key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true]]);

        try {
            $this->service()->decide(self::UUID, 'approved', null, [], 'admin', '5');
            $this->fail('Expected required-payload rejection on approve');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_PAYLOAD_REQUIRED_MISSING, $e->getApprovalCode());
        }
        $this->assertSame([], $this->connection->updates);

        // Rejecting the same task needs no payload.
        $this->service()->decide(self::UUID, 'rejected', null, [], 'admin', '5');
        $this->assertSame('rejected', $this->connection->approvals[$id]['status']);
    }

    public function testDecisionVsDecisionSecondClaimIsAlreadyDecided(): void
    {
        // The task was already claimed by a competing decider.
        $this->seed(null, null, ApprovalInterface::STATUS_APPROVED);
        try {
            $this->service()->decide(self::UUID, 'rejected', null, [], 'admin', '6');
            $this->fail('Expected already-decided rejection');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_ALREADY_DECIDED, $e->getApprovalCode());
            $this->assertStringContainsString('approved', $e->getMessage());
        }
    }

    public function testDecisionVsTimeoutRollsTaskBackToOpen(): void
    {
        $id = $this->seed();
        // The sweeper claimed the execution microseconds earlier.
        $this->connection->executionClaimResult = 0;

        try {
            $this->service()->decide(self::UUID, 'approved', 'note', [], 'admin', '5');
            $this->fail('Expected execution-gone rejection');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_EXECUTION_GONE, $e->getApprovalCode());
        }

        // The task claim is rolled back so the timeout path can mark it expired.
        $row = $this->connection->approvals[$id];
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $row['status']);
        $this->assertNull($row[ApprovalInterface::DECIDED_BY_TYPE]);
        $this->assertNull($row[ApprovalInterface::DECIDED_AT]);
        $this->assertSame([], $this->publisher->published);
    }

    public function testStaleTaskOnLaterParkIsRejectedAndRolledBack(): void
    {
        // A best-effort expireTask miss left this task open while its execution
        // routed on_timeout and parked again at a LATER step. The execution IS
        // waiting — just not on this gate — so the current_step-scoped claim
        // must miss: the decision may not hijack a park it does not own.
        $id = $this->seed();
        $this->connection->executions[self::EXEC_ID]['current_step'] = 'later_wait';

        try {
            $this->service()->decide(self::UUID, 'approved', 'note', [], 'admin', '5');
            $this->fail('Expected execution-gone rejection for the stale task');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_EXECUTION_GONE, $e->getApprovalCode());
        }

        // Task claim rolled back so the reconciliation sweep can expire it.
        $row = $this->connection->approvals[$id];
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $row['status']);
        $this->assertNull($row[ApprovalInterface::DECIDED_BY_TYPE]);
        // The later park is untouched: still waiting, no result write, no resume.
        $this->assertSame('waiting', $this->connection->executions[self::EXEC_ID]['status']);
        $this->assertSame([], $this->connection->stepResults);
        $this->assertSame([], $this->publisher->published);
    }

    public function testPublishFailureRollsBackBothClaimsAndClearsResult(): void
    {
        $id = $this->seed();
        $this->publisher->throwOnPublish = true;

        try {
            $this->service()->decide(self::UUID, 'approved', 'note', [], 'admin', '5');
            $this->fail('Expected the publish failure to escape');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('queue unavailable', $e->getMessage());
        }

        $row = $this->connection->approvals[$id];
        $this->assertSame(ApprovalInterface::STATUS_OPEN, $row['status']);
        $this->assertNull($row[ApprovalInterface::DECIDED_BY_TYPE]);
        $this->assertSame('waiting', $this->connection->executions[self::EXEC_ID]['status']);
        $this->assertNull($this->connection->stepResults[self::EXEC_ID]);
        // Result written, publish attempted, then result cleared on rollback.
        $this->assertSame(['result:write', 'publish', 'result:clear'], $this->log->entries);
    }

    public function testRoleEnforcementDeniesWithoutRole(): void
    {
        $this->seed(null, 'sales_managers');
        try {
            $this->service(false)->decide(self::UUID, 'approved', null, [], 'admin', '5');
            $this->fail('Expected role rejection');
        } catch (ApprovalDecisionException $e) {
            $this->assertSame(ApprovalDecisionException::CODE_ROLE_REQUIRED, $e->getApprovalCode());
        }
        // Denied before any claim.
        $this->assertSame([], $this->connection->updates);
    }

    public function testRoleEnforcementAllowsWithRole(): void
    {
        $id = $this->seed(null, 'sales_managers');
        $this->service(true)->decide(self::UUID, 'approved', null, [], 'admin', '5');
        $this->assertSame('approved', $this->connection->approvals[$id]['status']);
    }
}
