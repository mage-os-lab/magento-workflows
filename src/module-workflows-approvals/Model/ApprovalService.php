<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\WorkflowsApprovals\Api\ApprovalAuthorizationInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalDecisionResultInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;
use Psr\Log\LoggerInterface;

/**
 * The single decision path for approval gates (docs/discovery/approval-gate.md
 * §4) — the admin UI (Stage 3) and REST both call decide(), so races,
 * validation, and audit are identical. A near-clone of Dispatcher::resumeWaiting:
 * atomic task claim, atomic execution claim, result-before-publish, rollback on
 * publish failure. Persistence is direct via ResourceConnection so each claim is
 * one conditional statement.
 */
class ApprovalService
{
    public const TOPIC_RESUME = 'mageos.workflow.resume';

    private const APPROVAL_TABLE = 'mageos_workflow_approval';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    private const DECISIONS = [ApprovalInterface::STATUS_APPROVED, ApprovalInterface::STATUS_REJECTED];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PublisherInterface $publisher,
        private readonly DecisionPayloadValidator $payloadValidator,
        private readonly ApprovalAuthorizationInterface $authorization,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $uuid the task handle
     * @param string $decision 'approved' | 'rejected'
     * @param string|null $note free-text note (capped)
     * @param array<string, mixed> $payload flat scalar payload (§5)
     * @param string $actorType 'admin' | 'integration'
     * @param string $actorId the deciding actor's id
     * @throws NoSuchEntityException when no open/decidable task carries the uuid
     * @throws ApprovalDecisionException on validation, authorization, or a lost race
     * @throws \Throwable when the resume publish fails (after both claims roll back)
     */
    public function decide(
        string $uuid,
        string $decision,
        ?string $note,
        array $payload,
        string $actorType,
        string $actorId
    ): ApprovalDecisionResultInterface {
        // (a) decision domain + task load.
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_INVALID_DECISION,
                new Phrase('Decision must be "approved" or "rejected".')
            );
        }
        $task = $this->loadTask($uuid);
        $executionId = (int) $task[ApprovalInterface::EXECUTION_ID];
        $stepKey = (string) $task[ApprovalInterface::STEP_KEY];
        $isApproved = $decision === ApprovalInterface::STATUS_APPROVED;

        // (b) payload + note validation BEFORE any claim (§5): a rejected
        // payload never touches task or execution state.
        $declaration = $this->payloadDeclaration($executionId, $stepKey);
        $this->payloadValidator->validateNote($note);
        $coerced = $this->payloadValidator->validate($payload, $declaration, $isApproved);

        // (c) role enforcement seam: enforced at decide time when the task
        // carries a role (§5), deny-safe by default.
        $assigneeRole = $task[ApprovalInterface::ASSIGNEE_ROLE] ?? null;
        if ($assigneeRole !== null && $assigneeRole !== ''
            && !$this->authorization->actorHoldsRole($actorType, $actorId, (string) $assigneeRole)
        ) {
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_ROLE_REQUIRED,
                new Phrase('Deciding this task requires the "%1" role.', [(string) $assigneeRole])
            );
        }

        $connection = $this->resourceConnection->getConnection();
        $approvalTable = $this->resourceConnection->getTableName(self::APPROVAL_TABLE);
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);
        $now = gmdate('Y-m-d H:i:s');

        // (d) task claim — first decision wins; the loser gets "already decided".
        $claimed = $connection->update(
            $approvalTable,
            [
                ApprovalInterface::STATUS => $decision,
                ApprovalInterface::DECIDED_BY_TYPE => $actorType,
                ApprovalInterface::DECIDED_BY_ID => $actorId,
                ApprovalInterface::DECISION_NOTE => $note,
                ApprovalInterface::DECISION_PAYLOAD => $coerced !== [] ? $this->encode($coerced) : null,
                ApprovalInterface::DECIDED_AT => $now,
            ],
            ['uuid = ?' => $uuid, 'status = ?' => ApprovalInterface::STATUS_OPEN]
        );
        if ($claimed !== 1) {
            $current = (string) $connection->fetchOne(
                $connection->select()
                    ->from($approvalTable, [ApprovalInterface::STATUS])
                    ->where('uuid = ?', $uuid)
                    ->limit(1)
            );
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_ALREADY_DECIDED,
                new Phrase('This task was already decided (status: %1).', [$current !== '' ? $current : 'unknown'])
            );
        }

        // (e) execution claim — the decision-vs-timeout arbiter, the exact
        // waiting -> pending posture the sweeper uses. If the sweeper claimed it
        // microseconds earlier, roll the task claim back to open and report the
        // conflict; the timeout path marks the task expired.
        $execClaimed = $connection->update(
            $executionTable,
            [WorkflowExecutionInterface::STATUS => WorkflowExecutionInterface::STATUS_PENDING],
            [
                'execution_id = ?' => $executionId,
                'status = ?' => WorkflowExecutionInterface::STATUS_WAITING,
            ]
        );
        if ($execClaimed !== 1) {
            $this->rollbackTaskClaim($connection, $approvalTable, $uuid, $decision);
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_EXECUTION_GONE,
                new Phrase('This task can no longer be decided; its execution was resumed by the timeout.')
            );
        }

        // (f) result before publish — write {resolution, note, payload,
        // decided_by} into the parked step row, then publish. On publish
        // failure roll BOTH claims back (the sweeper still owns the timeout).
        $decidedBy = $actorType . ':' . $actorId;
        $result = ['resolution' => $decision, 'decided_by' => $decidedBy];
        if ($note !== null && $note !== '') {
            $result['note'] = $note;
        }
        if ($coerced !== []) {
            $result['payload'] = $coerced;
        }

        $stepWhere = [
            'execution_id = ?' => $executionId,
            'status = ?' => WorkflowExecutionStepInterface::STATUS_WAITING,
        ];
        $connection->update($stepTable, ['result' => $this->encode($result)], $stepWhere);

        try {
            $this->publisher->publish(self::TOPIC_RESUME, (string) $executionId);
        } catch (\Throwable $e) {
            $connection->update($stepTable, ['result' => null], $stepWhere);
            $connection->update(
                $executionTable,
                [WorkflowExecutionInterface::STATUS => WorkflowExecutionInterface::STATUS_WAITING],
                [
                    'execution_id = ?' => $executionId,
                    'status = ?' => WorkflowExecutionInterface::STATUS_PENDING,
                ]
            );
            $this->rollbackTaskClaim($connection, $approvalTable, $uuid, $decision);
            $this->logger->error(sprintf(
                'Approval decision on task %s could not publish resume of execution %d: %s',
                $uuid,
                $executionId,
                $e->getMessage()
            ), ['exception' => $e]);
            throw $e;
        }

        $this->logger->info('approval_decided', [
            'uuid' => $uuid,
            'execution_id' => $executionId,
            'decision' => $decision,
            'decided_by' => $decidedBy,
        ]);

        return new ApprovalDecisionResult($uuid, $decision, $executionId);
    }

    /**
     * @return array<string, mixed> the task row
     * @throws NoSuchEntityException
     */
    private function loadTask(string $uuid): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::APPROVAL_TABLE);
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('uuid = ?', $uuid)
                ->limit(1)
        );
        if (!is_array($row) || $row === []) {
            throw NoSuchEntityException::singleField('uuid', $uuid);
        }
        return $row;
    }

    /**
     * The gate's config.payload_fields from the execution's pinned definition
     * snapshot, or null when the gate declares none (note-only, §5).
     *
     * @return array<int, array>|null
     */
    private function payloadDeclaration(int $executionId, string $stepKey): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $snapshot = (string) $connection->fetchOne(
            $connection->select()
                ->from($table, [WorkflowExecutionInterface::DEFINITION_SNAPSHOT])
                ->where('execution_id = ?', $executionId)
                ->limit(1)
        );
        if ($snapshot === '') {
            throw new ApprovalDecisionException(
                ApprovalDecisionException::CODE_EXECUTION_GONE,
                new Phrase('The execution for this task no longer exists.')
            );
        }

        $definition = Definition::fromJson($snapshot);
        if (!$definition->hasStep($stepKey)) {
            return null;
        }
        $config = $definition->getStep($stepKey)['config'] ?? null;
        $fields = is_array($config) ? ($config['payload_fields'] ?? null) : null;
        return is_array($fields) && $fields !== [] ? $fields : null;
    }

    /**
     * Roll a task claim back to open, clearing the decision audit fields. The
     * status guard on the just-written decision keeps the rollback from
     * clobbering a subsequent state.
     */
    private function rollbackTaskClaim(
        object $connection,
        string $approvalTable,
        string $uuid,
        string $decision
    ): void {
        $connection->update(
            $approvalTable,
            [
                ApprovalInterface::STATUS => ApprovalInterface::STATUS_OPEN,
                ApprovalInterface::DECIDED_BY_TYPE => null,
                ApprovalInterface::DECIDED_BY_ID => null,
                ApprovalInterface::DECISION_NOTE => null,
                ApprovalInterface::DECISION_PAYLOAD => null,
                ApprovalInterface::DECIDED_AT => null,
            ],
            ['uuid = ?' => $uuid, 'status = ?' => $decision]
        );
    }

    private function encode(mixed $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    }
}
