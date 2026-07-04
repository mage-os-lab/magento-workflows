<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Optional audit persistence of a dry-run (discovery §6): default-ON for admin
 * dry-runs of SAVED workflows. It writes a mode='dry_run', status=complete
 * execution row plus one step row per trace step, so the existing execution
 * view renders the preview and "who previewed what against which customer's
 * data" is auditable. Pruned aggressively by the extended PruneExecutions cron.
 *
 * mode='dry_run' is a feature marker, NOT a side-effect predicate: these rows
 * are always side-effect-free by construction. Unsaved-definition dry-runs are
 * never persisted (execution rows require a NOT-NULL workflow_id FK — an
 * accepted constraint).
 */
class DryRunPersister
{
    public const CONFIG_PERSIST = 'mageos_workflows/dry_run/persist';

    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    public function __construct(
        private readonly WorkflowExecutionInterfaceFactory $executionFactory,
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_PERSIST);
    }

    /**
     * Persist a dry-run of a saved workflow. Returns the new execution id, or
     * null when persistence is disabled, the workflow is unsaved, or the write
     * failed (a failed audit write must never break the preview).
     */
    public function persist(Trace $trace, WorkflowInterface $workflow, int $entityId, int $storeId): ?int
    {
        if (!$this->isEnabled() || $workflow->getWorkflowId() === null) {
            return null;
        }

        try {
            /** @var WorkflowExecutionInterface $execution */
            $execution = $this->executionFactory->create();
            $execution->setUuid($this->generateUuidV4());
            $execution->setWorkflowId((int) $workflow->getWorkflowId());
            $execution->setWorkflowVersion($workflow->getVersion());
            $execution->setDefinitionSnapshot($workflow->getDefinition());
            $execution->setEntityId($entityId);
            $execution->setStoreId($storeId);
            $execution->setStatus(WorkflowExecutionInterface::STATUS_COMPLETE);
            $execution->setMode(WorkflowExecutionInterface::MODE_DRY_RUN);
            $execution->setChainDepth(0);
            $execution->setCurrentStep(null);
            $execution->setContext($this->encodeJson([
                'trigger' => ['entity_id' => $entityId],
                'steps' => new \stdClass(),
                'workflow' => [
                    'id' => (int) $workflow->getWorkflowId(),
                    'name' => $workflow->getName(),
                    'version' => $workflow->getVersion(),
                    'entity_type' => $workflow->getEntityType(),
                ],
            ]));
            $execution = $this->executionRepository->save($execution);

            $this->writeSteps((int) $execution->getExecutionId(), $trace);
            $this->stampCompletedAt((int) $execution->getExecutionId());

            return (int) $execution->getExecutionId();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Could not persist dry-run audit row: %s', $e->getMessage()));
            return null;
        }
    }

    private function writeSteps(int $executionId, Trace $trace): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::STEP_TABLE);
        $now = gmdate('Y-m-d H:i:s');

        foreach ($trace->getSteps() as $step) {
            $connection->insert($table, [
                'execution_id' => $executionId,
                'step_key' => $step->getStepKey(),
                'status' => $this->mapStatus($step->getStatus()),
                'result' => $this->encodeJson($step->toArray()),
                'error' => $step->getStatus() === TraceStepStatus::WOULD_FAIL ? (string) $step->getWould() : null,
                'started_at' => $now,
                'finished_at' => $now,
            ]);
        }
    }

    private function mapStatus(TraceStepStatus $status): string
    {
        return match ($status) {
            TraceStepStatus::WOULD_RUN => 'complete',
            TraceStepStatus::WOULD_FAIL => 'failed',
            TraceStepStatus::SKIPPED, TraceStepStatus::PRODUCTION_STOPS_HERE => 'skipped',
        };
    }

    private function stampCompletedAt(int $executionId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::EXECUTION_TABLE),
            [WorkflowExecutionInterface::COMPLETED_AT => gmdate('Y-m-d H:i:s')],
            [WorkflowExecutionInterface::EXECUTION_ID . ' = ?' => $executionId]
        );
    }

    private function encodeJson(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
