<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ApprovalTaskManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Engine\Executor;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the mageos.workflow.resume topic: wakes an execution parked by
 * a delay, wait, or approval step. The waiting step is marked complete, the
 * execution goes running (so the root-condition gate does NOT re-fire — that
 * is first-run only), and the walk continues from current_step.
 *
 * Routing keys off the PARKED STEP'S TYPE read from the definition snapshot,
 * not the presence of a waiting_event:
 *  - Delay steps: current_step already points AFTER the delay (Executor set
 *    it); nothing to route.
 *  - Wait steps (schema v2): current_step is the wait step; route to on_event
 *    / on_timeout from the step row's result written by
 *    Dispatcher::resumeWaiting ('event' + payload) or left absent by the
 *    timeout sweeper; exposes {resolution, event} as the step's output.
 *  - Approval steps (schema v4): current_step is the gate; route
 *    approved → on_approved, rejected → on_rejected, anything else/absent →
 *    on_timeout (and expire the task through the seam). The addon's decision
 *    service writes {resolution, note, payload, decided_by} into the step
 *    result before publishing; that shape becomes the step's output.
 */
class ResumeConsumer
{
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly Executor $executor,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger,
        private readonly ?ApprovalTaskManagerInterface $approvalTaskManager = null
    ) {
    }

    /**
     * @throws \Throwable retryable failures rethrow for queue redelivery
     */
    public function process(string $executionId): void
    {
        $id = (int) $executionId;
        try {
            $execution = $this->executionRepository->getById($id);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(sprintf('Workflow resume: execution %d not found; message dropped', $id));
            return;
        }

        $status = $execution->getStatus();
        if (in_array($status, [
            WorkflowExecutionInterface::STATUS_COMPLETE,
            WorkflowExecutionInterface::STATUS_CANCELLED,
            WorkflowExecutionInterface::STATUS_FAILED,
            WorkflowExecutionInterface::STATUS_SKIPPED,
        ], true)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $stepTable = $this->resourceConnection->getTableName(self::STEP_TABLE);

        // Snapshot the parked step before closing it: wait routing needs its
        // key and the resolution the resume path recorded.
        //
        // The limit(1) is a pick, so it is ordered. It used to be able to pick
        // between two rows for the SAME step (the old racy step upsert could
        // duplicate them); the unique key on (execution_id, step_key) ended
        // that. What remains is the one crash shape that can leave a stale
        // waiting row from an EARLIER park alongside the current one, and for
        // that "newest row wins" is the right answer — the current park is the
        // one this resume is about. Both rows are closed by the UPDATE below
        // either way; only the routing needs the pick to be deterministic.
        $parked = $connection->fetchRow(
            $connection->select()
                ->from($stepTable, ['step_key', 'result'])
                ->where('execution_id = ?', $id)
                ->where('status = ?', WorkflowExecutionStepInterface::STATUS_WAITING)
                ->order('step_execution_id DESC')
                ->limit(1)
        );

        // Close out the delay/wait step that parked this execution
        $connection->update(
            $stepTable,
            [
                'status' => WorkflowExecutionStepInterface::STATUS_COMPLETE,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'execution_id = ?' => $id,
                'status = ?' => WorkflowExecutionStepInterface::STATUS_WAITING,
            ]
        );

        if (is_array($parked)) {
            $this->routeParkedStep($execution, (string) $parked['step_key'], $parked['result'] ?? null);
        }

        $execution->setStatus(WorkflowExecutionInterface::STATUS_RUNNING);
        $this->executionRepository->save($execution);

        try {
            $this->executor->execute($id);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Workflow resume of execution %d hit a retryable failure: %s', $id, $e->getMessage()),
                ['exception' => $e]
            );
            throw $e;
        }
    }

    /**
     * Dispatch on the parked step's type from the pinned definition snapshot.
     * Delay parks need no routing (current_step already points past them);
     * wait and approval parks each route their own edges.
     */
    private function routeParkedStep(
        WorkflowExecutionInterface $execution,
        string $stepKey,
        ?string $resultJson
    ): void {
        try {
            $definition = Definition::fromJson($execution->getDefinitionSnapshot());
        } catch (\InvalidArgumentException $e) {
            // Corrupt snapshot: let the executor fail the execution uniformly
            $this->logger->error(sprintf(
                'Resume routing of execution %d found an invalid definition snapshot: %s',
                (int) $execution->getExecutionId(),
                $e->getMessage()
            ));
            return;
        }
        if (!$definition->hasStep($stepKey)) {
            return;
        }

        switch ($definition->getStep($stepKey)['type'] ?? null) {
            case Definition::STEP_WAIT:
                $this->routeWaitStep($execution, $definition, $stepKey, $resultJson);
                break;
            case Definition::STEP_APPROVAL:
                $this->routeApprovalStep($execution, $definition, $stepKey, $resultJson);
                break;
            // delay: current_step already points AFTER the delay, no routing.
        }
    }

    /**
     * Route a resumed wait step: pick on_event / on_timeout from the pinned
     * definition and surface {resolution, event} as the step's output so
     * later steps can interpolate {{ steps.<key>.event.* }}.
     */
    private function routeWaitStep(
        WorkflowExecutionInterface $execution,
        Definition $definition,
        string $stepKey,
        ?string $resultJson
    ): void {
        $resolution = 'timeout';
        $eventPayload = null;
        if (is_string($resultJson) && $resultJson !== '') {
            $decoded = json_decode($resultJson, true);
            if (is_array($decoded) && ($decoded['resolution'] ?? null) === 'event') {
                $resolution = 'event';
                $eventPayload = is_array($decoded['event'] ?? null) ? $decoded['event'] : null;
            }
        }

        $edges = $definition->getStepEdges($stepKey);
        $edge = $resolution === 'event' ? $edges['on_event'] : $edges['on_timeout'];

        $this->injectStepOutput($execution, $stepKey, array_filter([
            'resolution' => $resolution,
            'event' => $eventPayload,
        ], static fn ($v) => $v !== null));

        $execution->setCurrentStep($edge !== null ? (string) $edge : null);
        $execution->setWaitingEvent(null);
    }

    /**
     * Route a resumed approval gate (schema v4): approved → on_approved,
     * rejected → on_rejected, anything else/absent → on_timeout. The addon's
     * ApprovalService writes {resolution, note, payload, decided_by} into the
     * step result before publishing a decision; the timeout sweeper leaves no
     * result. Approved/rejected surface that full shape (nulls filtered) as the
     * step output; a timeout surfaces only {resolution:'timeout'} and expires
     * the open task through the seam (skipped silently when the addon is
     * uninstalled — parked gates still resume by timeout, but tasks orphan).
     */
    private function routeApprovalStep(
        WorkflowExecutionInterface $execution,
        Definition $definition,
        string $stepKey,
        ?string $resultJson
    ): void {
        $decoded = [];
        if (is_string($resultJson) && $resultJson !== '') {
            $maybe = json_decode($resultJson, true);
            if (is_array($maybe)) {
                $decoded = $maybe;
            }
        }
        $resolution = $decoded['resolution'] ?? null;
        $edges = $definition->getStepEdges($stepKey);

        if ($resolution === 'approved' || $resolution === 'rejected') {
            $edge = $resolution === 'approved' ? $edges['on_approved'] : $edges['on_rejected'];
            $output = array_filter([
                'resolution' => $resolution,
                'note' => $decoded['note'] ?? null,
                'payload' => is_array($decoded['payload'] ?? null) ? $decoded['payload'] : null,
                'decided_by' => $decoded['decided_by'] ?? null,
            ], static fn ($v) => $v !== null);
        } else {
            $edge = $edges['on_timeout'];
            $output = ['resolution' => 'timeout'];
            if ($this->approvalTaskManager !== null) {
                // Best-effort expiry marking: the parked step row was already
                // closed above, so an escaping exception here would redeliver
                // into a routing loop (no waiting row found, routing skipped,
                // current_step still the gate, the executor re-parks it). Log
                // and keep routing on_timeout; the addon's reconciliation
                // sweep is the backstop for a task left open.
                try {
                    $this->approvalTaskManager->expireTask((int) $execution->getExecutionId(), $stepKey);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Expiring the approval task for execution %d step "%s" failed: %s',
                        (int) $execution->getExecutionId(),
                        $stepKey,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->injectStepOutput($execution, $stepKey, $output);
        $execution->setCurrentStep($edge !== null ? (string) $edge : null);
        $execution->setWaitingEvent(null);
    }

    private function injectStepOutput(WorkflowExecutionInterface $execution, string $stepKey, array $output): void
    {
        $raw = $execution->getContext();
        $data = [];
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        if (!is_array($data['steps'] ?? null)) {
            $data['steps'] = [];
        }
        $data['steps'][$stepKey] = $output;
        // Preserve {} (not []) for the empty maps, matching Executor::persistContext
        foreach (['trigger', 'workflow'] as $key) {
            if (($data[$key] ?? null) === []) {
                $data[$key] = new \stdClass();
            }
        }
        $execution->setContext(json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
