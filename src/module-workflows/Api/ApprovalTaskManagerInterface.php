<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api;

use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;

/**
 * The thin core seam an approval gate delegates its task lifecycle through
 * (docs/discovery/approval-gate.md §7). Core owns the step semantics — parser,
 * executor park, resume routing, dry-run, plain-language — and reaches the
 * approval-task record, admin surface, and decision service ONLY through this
 * interface. The addon module (MageOS_WorkflowsApprovals) binds an
 * implementation via di.xml; with no binding an approval step is unauthorable
 * (save-time APPROVAL_MODULE_MISSING) and a data-patched one fails terminally
 * at runtime — never a silent skip.
 *
 * Only the three lifecycle transitions core drives live here: create at park,
 * expire when the timeout wins, orphan when the execution dies otherwise. The
 * decision path (ApprovalService::decide) is the addon's alone and is not part
 * of this seam.
 */
interface ApprovalTaskManagerInterface
{
    /**
     * Open one approval task for a parked gate. Idempotent on
     * (execution_id, step_key): a redelivered park after a crash must re-attach
     * to the existing open task, not create a second — so this returns the
     * existing task's uuid when one is already open for the pair.
     *
     * @param WorkflowExecutionInterface $execution the parked execution
     * @param string $stepKey the approval step's key
     * @param string $title interpolated request label (park-time snapshot)
     * @param string $instructions interpolated instructions ('' when none)
     * @param string $dueAt the step's resume_at (UTC 'Y-m-d H:i:s') — the SLA clock
     * @param string|null $assigneeRole nullable role code the decision requires
     * @return string the task uuid (the API/deep-link handle, exposed as the
     *                step's {{ steps.<key>.task_uuid }} output)
     */
    public function createTask(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        string $title,
        string $instructions,
        string $dueAt,
        ?string $assigneeRole
    ): string;

    /**
     * Mark the open task for this gate expired: the timeout won the race. Called
     * from the resume routing when a parked approval woke with no decision.
     */
    public function expireTask(int $executionId, string $stepKey): void;

    /**
     * Mark every open task of this execution orphaned: the execution left the
     * waiting state by a path other than a decision or a timeout (failure, a
     * future cancel surface). An open task whose execution is terminal is a bug
     * marker, not a valid state.
     */
    public function orphanTasks(int $executionId): void;
}
