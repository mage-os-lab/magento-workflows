<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Plugin\Notification;

use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\WorkflowsApprovals\Model\GateConfigReader;
use MageOS\WorkflowsApprovals\Model\Notification\ParkNotifier;
use Psr\Log\LoggerInterface;

/**
 * Fires the park notification (docs/discovery/approval-gate.md §6) around task
 * creation. The gate owns this because only the gate knows the task uuid — a
 * notify.* step before the gate cannot link to a task that does not exist yet
 * (§6). An `around` plugin, not `after`: createTask() is idempotent on
 * (execution_id, step_key) for crash-safe redelivery (Stage 2), but that
 * idempotency must not become duplicate notifications — a redelivered park
 * re-attaches to the already-open task without re-notifying. Checking
 * existence BEFORE calling the wrapped method (rather than trusting the
 * returned uuid, which is identical on both paths) is the only way to tell
 * "created" from "re-attached" apart.
 *
 * Notification failure must never fail the park: ParkNotifier already guards
 * every channel internally, and config/lookup errors here are caught too.
 */
class NotifyOnTaskCreation
{
    private const APPROVAL_TABLE = 'mageos_workflow_approval';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly GateConfigReader $gateConfigReader,
        private readonly ParkNotifier $parkNotifier,
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundCreateTask(
        ApprovalTaskManagerInterface $subject,
        callable $proceed,
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $title,
        string $instructions,
        string $dueAt,
        ?string $assigneeRole
    ): string {
        $executionId = (int) $execution->getExecutionId();
        $isNew = !$this->taskAlreadyExists($executionId, $stepKey);

        $uuid = $proceed($execution, $stepKey, $title, $instructions, $dueAt, $assigneeRole);

        if ($isNew) {
            $this->safeNotify($uuid, $executionId, $stepKey, $title, $instructions, (int) $execution->getStoreId());
        }

        return $uuid;
    }

    private function safeNotify(
        string $uuid,
        int $executionId,
        string $stepKey,
        string $title,
        string $instructions,
        int $storeId
    ): void {
        try {
            $notifyEmails = $this->gateConfigReader->getNotifyEmails($executionId, $stepKey);
            $this->parkNotifier->notify($uuid, $title, $instructions, $notifyEmails, $storeId);
        } catch (\Throwable $e) {
            // Never let a notification-composition failure fail the park.
            $this->logger->error(sprintf(
                'Approval park notification setup failed for task %s: %s',
                $uuid,
                $e->getMessage()
            ), ['exception' => $e]);
        }
    }

    private function taskAlreadyExists(int $executionId, string $stepKey): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::APPROVAL_TABLE);
        $row = $connection->fetchOne(
            $connection->select()
                ->from($table, ['approval_id'])
                ->where('execution_id = ?', $executionId)
                ->where('step_key = ?', $stepKey)
                ->limit(1)
        );
        return $row !== false && $row !== null;
    }
}
