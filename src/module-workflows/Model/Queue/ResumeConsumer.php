<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Definition\Definition;
use MageOS\Workflows\Model\Engine\Executor;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the mageos.workflow.resume topic: wakes an execution parked by
 * a delay or wait step. The waiting step is marked complete, the execution
 * goes running (so the root-condition gate does NOT re-fire — that is
 * first-run only), and the walk continues from current_step.
 *
 * Delay steps: current_step already points AFTER the delay (Executor set it).
 * Wait steps (schema v2): current_step is the wait step itself; this consumer
 * routes to on_event or on_timeout based on the step row's result written by
 * Dispatcher::resumeWaiting ('event' + payload) or left absent by the timeout
 * sweeper, and exposes {resolution, event} as the wait step's output.
 */
class ResumeConsumer
{
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    public function __construct(
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly Executor $executor,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
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
        $parked = $connection->fetchRow(
            $connection->select()
                ->from($stepTable, ['step_key', 'result'])
                ->where('execution_id = ?', $id)
                ->where('status = ?', WorkflowExecutionStepInterface::STATUS_WAITING)
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

        if ($execution->getWaitingEvent() !== null && is_array($parked)) {
            $this->routeWaitStep($execution, (string) $parked['step_key'], $parked['result'] ?? null);
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
     * Route a resumed wait step: pick on_event / on_timeout from the pinned
     * definition and surface {resolution, event} as the step's output so
     * later steps can interpolate {{ steps.<key>.event.* }}.
     */
    private function routeWaitStep(
        WorkflowExecutionInterface $execution,
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

        $edge = null;
        try {
            $definition = Definition::fromJson($execution->getDefinitionSnapshot());
            if ($definition->hasStep($stepKey)) {
                $step = $definition->getStep($stepKey);
                if (($step['type'] ?? null) === Definition::STEP_WAIT) {
                    $edge = $resolution === 'event'
                        ? ($step['on_event'] ?? null)
                        : ($step['on_timeout'] ?? null);
                }
            }
        } catch (\InvalidArgumentException $e) {
            // Corrupt snapshot: let the executor fail the execution uniformly
            $this->logger->error(sprintf(
                'Wait routing of execution %d found an invalid definition snapshot: %s',
                (int) $execution->getExecutionId(),
                $e->getMessage()
            ));
            return;
        }

        $this->injectStepOutput($execution, $stepKey, array_filter([
            'resolution' => $resolution,
            'event' => $eventPayload,
        ], static fn ($v) => $v !== null));

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
