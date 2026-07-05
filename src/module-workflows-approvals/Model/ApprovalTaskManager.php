<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * The addon's implementation of the core task seam
 * (docs/discovery/approval-gate.md §7). Drives the three lifecycle transitions
 * core delegates: open at park, expire when the timeout wins, orphan when the
 * execution dies otherwise. Persistence is direct via ResourceConnection (the
 * same posture as the engine) so every transition is a single atomic statement.
 */
class ApprovalTaskManager implements ApprovalTaskManagerInterface
{
    private const APPROVAL_TABLE = 'mageos_workflow_approval';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     *
     * Idempotent on (execution_id, step_key): INSERT first and, on the unique
     * duplicate key, fetch the existing row's uuid — never SELECT-then-INSERT,
     * which would race a redelivered park (mirrors Dispatcher::passesDebounce).
     */
    public function createTask(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $title,
        string $instructions,
        string $dueAt,
        ?string $assigneeRole
    ): string {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::APPROVAL_TABLE);
        $executionId = (int) $execution->getExecutionId();

        $uuid = $this->generateUuidV4();

        try {
            $connection->insert($table, [
                ApprovalInterface::UUID => $uuid,
                ApprovalInterface::EXECUTION_ID => $executionId,
                ApprovalInterface::STEP_KEY => $stepKey,
                ApprovalInterface::WORKFLOW_ID => (int) $execution->getWorkflowId(),
                ApprovalInterface::ENTITY_TYPE => $this->entityTypeOf($execution),
                ApprovalInterface::ENTITY_ID => (int) $execution->getEntityId(),
                ApprovalInterface::TITLE => $title,
                ApprovalInterface::INSTRUCTIONS => $instructions !== '' ? $instructions : null,
                ApprovalInterface::ASSIGNEE_ROLE => $assigneeRole,
                ApprovalInterface::STATUS => ApprovalInterface::STATUS_OPEN,
                ApprovalInterface::DUE_AT => $dueAt,
                ApprovalInterface::CREATED_AT => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (AlreadyExistsException $e) {
            return $this->existingUuid($executionId, $stepKey);
        } catch (\Magento\Framework\DB\Adapter\DuplicateException $e) {
            return $this->existingUuid($executionId, $stepKey);
        } catch (\Exception $e) {
            if ($this->isDuplicateKeyException($e)) {
                return $this->existingUuid($executionId, $stepKey);
            }
            throw $e;
        }

        return $uuid;
    }

    /**
     * @inheritDoc
     *
     * Scoped to the open row only: a decision that already claimed this task
     * ('approved'/'rejected') must not be overwritten by a losing timeout race.
     */
    public function expireTask(int $executionId, string $stepKey): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::APPROVAL_TABLE),
            [ApprovalInterface::STATUS => ApprovalInterface::STATUS_EXPIRED],
            [
                'execution_id = ?' => $executionId,
                'step_key = ?' => $stepKey,
                'status = ?' => ApprovalInterface::STATUS_OPEN,
            ]
        );
    }

    /**
     * @inheritDoc
     *
     * Only 'open' tasks orphan — already-decided rows keep their audit trail.
     */
    public function orphanTasks(int $executionId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::APPROVAL_TABLE),
            [ApprovalInterface::STATUS => ApprovalInterface::STATUS_ORPHANED],
            [
                'execution_id = ?' => $executionId,
                'status = ?' => ApprovalInterface::STATUS_OPEN,
            ]
        );
    }

    /**
     * The uuid of the task already open for this (execution_id, step_key) — the
     * duplicate-key path's re-attach handle.
     */
    private function existingUuid(int $executionId, string $stepKey): string
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::APPROVAL_TABLE);
        $uuid = $connection->fetchOne(
            $connection->select()
                ->from($table, [ApprovalInterface::UUID])
                ->where('execution_id = ?', $executionId)
                ->where('step_key = ?', $stepKey)
                ->limit(1)
        );
        return (string) $uuid;
    }

    /**
     * entity_type is denormalized from the execution's pinned context workflow
     * block (Dispatcher::createExecution writes workflow.entity_type there) —
     * the cheapest reliable source: it is already loaded on the execution and
     * needs no workflow-repo round-trip, and it is the type pinned at trigger
     * time rather than the possibly-since-edited live workflow.
     */
    private function entityTypeOf(WorkflowExecutionInterface $execution): string
    {
        $raw = $execution->getContext();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && is_array($decoded['workflow'] ?? null)) {
                return (string) ($decoded['workflow']['entity_type'] ?? '');
            }
        }
        return '';
    }

    private function isDuplicateKeyException(\Exception $e): bool
    {
        do {
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                return true;
            }
            if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                return true;
            }
            $e = $e->getPrevious();
        } while ($e instanceof \Exception);
        return false;
    }

    /**
     * RFC 4122 v4 UUID from random bytes (matches Dispatcher::generateUuidV4).
     */
    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
