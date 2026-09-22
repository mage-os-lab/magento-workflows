<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;
use MageOS\WorkflowsApprovals\Model\Exception\ApprovalDecisionException;
use MageOS\WorkflowsApprovals\Model\Exception\MassDecideCapExceededException;
use Psr\Log\LoggerInterface;

/**
 * The mass-decide loop behind the grid's Approve/Reject mass actions
 * (docs/discovery/approval-gate.md §6). Per row: the same claims, the same
 * audit trail, one shared note, empty payload — ApprovalService::decide() is
 * the single decision path for the grid, the decision view, and REST alike.
 * Rows whose gate did not opt into allow_bulk are skipped and counted, never
 * silently decided (server-side enforcement, not just a grid filter).
 */
class MassDecideProcessor
{
    public function __construct(
        private readonly ApprovalService $approvalService,
        private readonly BulkDecideFilter $bulkDecideFilter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param ApprovalInterface[] $tasks the selected rows (any status; ineligible ones are counted, not decided)
     * @param string $decision 'approved' | 'rejected'
     * @throws MassDecideCapExceededException before any row is touched when $tasks exceeds $cap
     */
    public function process(
        array $tasks,
        string $decision,
        ?string $note,
        string $actorType,
        string $actorId,
        int $cap
    ): MassDecideResult {
        if (count($tasks) > $cap) {
            throw new MassDecideCapExceededException(count($tasks), $cap);
        }

        $result = new MassDecideResult();
        foreach ($tasks as $task) {
            $this->processOne($task, $decision, $note, $actorType, $actorId, $result);
        }
        return $result;
    }

    private function processOne(
        ApprovalInterface $task,
        string $decision,
        ?string $note,
        string $actorType,
        string $actorId,
        MassDecideResult $result
    ): void {
        if (!$this->bulkDecideFilter->isEligible($task)) {
            if ($task->getStatus() !== ApprovalInterface::STATUS_OPEN) {
                $result->addAlreadyDecided();
            } else {
                $result->addSkippedNotBulk();
            }
            return;
        }

        try {
            $this->approvalService->decide($task->getUuid(), $decision, $note, [], $actorType, $actorId);
            $result->addDecided();
        } catch (ApprovalDecisionException $e) {
            if (in_array($e->getApprovalCode(), [
                ApprovalDecisionException::CODE_ALREADY_DECIDED,
                ApprovalDecisionException::CODE_EXECUTION_GONE,
            ], true)) {
                $result->addAlreadyDecided();
                return;
            }
            $this->logRowFailure($task, $e);
            $result->addFailed();
        } catch (\Throwable $e) {
            $this->logRowFailure($task, $e);
            $result->addFailed();
        }
    }

    private function logRowFailure(ApprovalInterface $task, \Throwable $e): void
    {
        $this->logger->error(sprintf(
            'Mass-decide failed for task %s: %s',
            $task->getUuid(),
            $e->getMessage()
        ), ['exception' => $e]);
    }
}
