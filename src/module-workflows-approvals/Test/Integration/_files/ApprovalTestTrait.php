<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Integration\_files;

use Magento\Authorization\Model\UserContextInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Model\Queue\ExecuteConsumer;
use MageOS\Workflows\Model\Queue\ResumeConsumer;
use MageOS\Workflows\Test\Integration\_files\WorkflowEngineTestTrait;
use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\ApprovalService;

/**
 * Approval-suite helpers (docs/20-integration-test-plan.md §2.4, suite #26).
 * Builds on the engine-spine trait (workflow save, execution seeding, timestamp
 * rewinding) and adds the approval-gate specifics: a stop-step gate definition
 * (so the resumed walk terminates with no real entity/action needed), the
 * park/decide/resume drivers via the REAL DI consumers (approvalTaskManager
 * bound), task-row lookups, and raw authorization_role seeding for the role
 * enforcement path.
 *
 * Not a test — a trait mixed into the suites, autoloaded via the module PSR-4
 * root (MageOS\WorkflowsApprovals\ => module dir).
 */
trait ApprovalTestTrait
{
    use WorkflowEngineTestTrait;

    /**
     * An approval-gate definition (schema 4). The gate is the entry step; each
     * edge (on_approved/on_rejected/on_timeout) lands on its own `stop` step so
     * the resumed walk completes deterministically and the branch taken is
     * observable from the persisted step rows — no real order/action required.
     *
     * @param array<string, mixed> $gateConfig merged over the base gate config
     * @return array<string, mixed>
     */
    protected function approvalDefinition(array $gateConfig = []): array
    {
        $config = array_merge(['title' => 'Approve the order', 'timeout' => 'PT8H'], $gateConfig);

        return [
            'schema' => 4,
            'entry' => 'gate',
            'steps' => [
                'gate' => [
                    'type' => 'approval',
                    'config' => $config,
                    'on_approved' => 'approved_end',
                    'on_rejected' => 'rejected_end',
                    'on_timeout' => 'timeout_end',
                ],
                'approved_end' => ['type' => 'stop'],
                'rejected_end' => ['type' => 'stop'],
                'timeout_end' => ['type' => 'stop'],
            ],
        ];
    }

    /**
     * Create an approval workflow, seed a pending execution, and drive it
     * through the real ExecuteConsumer so the gate parks and its task is
     * created via the bound ApprovalTaskManagerInterface seam.
     *
     * @param array<string, mixed> $gateConfig
     * @return array{0: int, 1: int} [workflowId, executionId]
     */
    protected function parkApproval(array $gateConfig = [], int $entityId = 4242): array
    {
        $workflow = $this->createWorkflow([
            'name' => 'approval gate ' . uniqid('', true),
            'definition' => $this->approvalDefinition($gateConfig),
        ]);
        $execution = $this->seedExecution(
            (int) $workflow->getWorkflowId(),
            $workflow->getDefinition(),
            $entityId,
            1
        );
        $executionId = (int) $execution->getExecutionId();
        $this->om()->get(ExecuteConsumer::class)->process((string) $executionId);

        return [(int) $workflow->getWorkflowId(), $executionId];
    }

    protected function approvalService(): ApprovalService
    {
        return $this->om()->get(ApprovalService::class);
    }

    /**
     * Drive one resume delivery through the real DI ResumeConsumer (the message
     * the ResumeSweeper / decision publish would deliver).
     */
    protected function resume(int $executionId): void
    {
        $this->om()->get(ResumeConsumer::class)->process((string) $executionId);
    }

    /**
     * @return array<string, mixed>|null the single approval task row for this execution
     */
    protected function taskRow(int $executionId): ?array
    {
        $connection = $this->db();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table('mageos_workflow_approval'))
                ->where('execution_id = ?', $executionId)
                ->limit(1)
        );
        return is_array($row) && $row !== [] ? $row : null;
    }

    protected function taskUuid(int $executionId): string
    {
        return (string) ($this->taskRow($executionId)[ApprovalInterface::UUID] ?? '');
    }

    protected function taskStatus(int $executionId): ?string
    {
        $row = $this->taskRow($executionId);
        return $row === null ? null : (string) $row[ApprovalInterface::STATUS];
    }

    /**
     * @return array<string, mixed> the parked step's decoded `result`, or []
     */
    protected function stepResultDecoded(int $executionId, string $stepKey): array
    {
        $connection = $this->db();
        $raw = $connection->fetchOne(
            $connection->select()
                ->from($this->table('mageos_workflow_execution_step'), ['result'])
                ->where('execution_id = ?', $executionId)
                ->where('step_key = ?', $stepKey)
                ->limit(1)
        );
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Force an execution's live status / current_step directly (the states the
     * reconciliation sweep keys off).
     */
    protected function setExecutionState(int $executionId, string $status, ?string $currentStep): void
    {
        $this->db()->update(
            $this->table('mageos_workflow_execution'),
            [
                WorkflowExecutionInterface::STATUS => $status,
                WorkflowExecutionInterface::CURRENT_STEP => $currentStep,
            ],
            ['execution_id = ?' => $executionId]
        );
    }

    /**
     * Insert an open approval task row directly (deterministic setup for the
     * reconciliation sweep, bypassing the notification plugin the seam carries).
     */
    protected function insertOpenTask(
        int $executionId,
        string $stepKey,
        int $workflowId,
        int $entityId = 4242,
        ?string $assigneeRole = null
    ): string {
        $uuid = $this->uuid();
        $this->db()->insert($this->table('mageos_workflow_approval'), [
            ApprovalInterface::UUID => $uuid,
            ApprovalInterface::EXECUTION_ID => $executionId,
            ApprovalInterface::STEP_KEY => $stepKey,
            ApprovalInterface::WORKFLOW_ID => $workflowId,
            ApprovalInterface::ENTITY_TYPE => 'sales_order',
            ApprovalInterface::ENTITY_ID => $entityId,
            ApprovalInterface::TITLE => 'Reconcile fixture task',
            ApprovalInterface::ASSIGNEE_ROLE => $assigneeRole,
            ApprovalInterface::STATUS => ApprovalInterface::STATUS_OPEN,
            ApprovalInterface::CREATED_AT => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /**
     * Seed a Magento admin role (role_type 'G') and a user assignment row
     * (role_type 'U') so RoleTableAuthorization resolves the admin actor as
     * holding the named role — mirroring the pinned authorization_role schema
     * assumptions in RoleTableAuthorization's docblock. Rolled back with the
     * test transaction (@magentoDbIsolation).
     */
    protected function seedAdminRole(string $roleName, int $adminUserId): void
    {
        $connection = $this->db();
        $table = $this->table('authorization_role');
        $connection->insert($table, [
            'parent_id' => 0,
            'tree_level' => 1,
            'sort_order' => 0,
            'role_type' => 'G',
            'user_id' => 0,
            'user_type' => UserContextInterface::USER_TYPE_ADMIN,
            'role_name' => $roleName,
        ]);
        $groupRoleId = (int) $connection->lastInsertId($table);
        $connection->insert($table, [
            'parent_id' => $groupRoleId,
            'tree_level' => 2,
            'sort_order' => 0,
            'role_type' => 'U',
            'user_id' => $adminUserId,
            'user_type' => UserContextInterface::USER_TYPE_ADMIN,
            'role_name' => $roleName,
        ]);
    }
}
