<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Model\Engine\Executor;
use Psr\Log\LoggerInterface;

/**
 * Consumer for the mageos.workflow.resume topic: wakes an execution parked by
 * a delay step. The waiting delay step is marked complete, the execution goes
 * running (so the root-condition gate does NOT re-fire — that is first-run
 * only), and the walk continues from current_step (already pointed at the
 * step AFTER the delay by the Executor).
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

        // Close out the delay step that parked this execution
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::STEP_TABLE),
            [
                'status' => WorkflowExecutionStepInterface::STATUS_COMPLETE,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                'execution_id = ?' => $id,
                'status = ?' => WorkflowExecutionStepInterface::STATUS_WAITING,
            ]
        );

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
}
