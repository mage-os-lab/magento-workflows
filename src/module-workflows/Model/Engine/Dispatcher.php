<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\DispatcherInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Suppression\WorkflowSuppression;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for all trigger types (docs/08-execution-model.md).
 *
 * Guards, in order: workflow status, loop depth, bulk suppression, website
 * scope, atomic debounce. Survivors get an execution row (pending, definition
 * snapshot pinned) published to the mageos.workflow.execute queue.
 */
class Dispatcher implements DispatcherInterface
{
    public const TOPIC_EXECUTE = 'mageos.workflow.execute';

    public const CONFIG_DEBOUNCE_WINDOW = 'mageos_workflows/guards/debounce_window_seconds';
    public const DEFAULT_DEBOUNCE_WINDOW = 60;

    private const DEBOUNCE_TABLE = 'mageos_workflow_debounce';

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly WorkflowExecutionRepositoryInterface $executionRepository,
        private readonly WorkflowExecutionInterfaceFactory $executionFactory,
        private readonly WorkflowSuppression $suppression,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function dispatch(
        int $workflowId,
        array $triggerPayload,
        string $triggerType = 'event',
        int $chainDepth = 0
    ): ?WorkflowExecutionInterface {
        try {
            $workflow = $this->workflowRepository->getById($workflowId);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning(sprintf('Workflow dispatch skipped: workflow %d not found', $workflowId));
            return null;
        }

        $status = $workflow->getStatus();
        if ($status !== WorkflowInterface::STATUS_ENABLED && $status !== WorkflowInterface::STATUS_SHADOW) {
            return null;
        }

        if ($chainDepth > $workflow->getLoopGuardDepth()) {
            $this->logger->info('loop_suppressed', [
                'workflow_id' => $workflowId,
                'chain_depth' => $chainDepth,
                'loop_guard_depth' => $workflow->getLoopGuardDepth(),
            ]);
            return null;
        }

        if ($this->suppression->isSuppressed()) {
            $this->logger->debug('Workflow dispatch suppressed (bulk suppression active)', [
                'workflow_id' => $workflowId,
            ]);
            return null;
        }

        if (!$this->matchesScope($workflow, $triggerPayload)) {
            return null;
        }

        $entityId = $this->extractEntityId($triggerPayload);

        if (!$this->passesDebounce($workflowId, $entityId)) {
            $this->logger->debug('Workflow dispatch debounced', [
                'workflow_id' => $workflowId,
                'entity_id' => $entityId,
            ]);
            return null;
        }

        $execution = $this->createExecution($workflow, $triggerPayload, $entityId, $chainDepth);
        $execution = $this->executionRepository->save($execution);

        $this->publisher->publish(self::TOPIC_EXECUTE, (string) $execution->getExecutionId());

        return $execution;
    }

    /**
     * Website scope check: resolve store from payload store_id, map store to
     * website; a workflow with non-empty website IDs must include it.
     * No store_id in the payload = the check is skipped.
     */
    private function matchesScope(WorkflowInterface $workflow, array $payload): bool
    {
        $websiteIds = $workflow->getWebsiteIds();
        if ($websiteIds === []) {
            return true;
        }
        if (!isset($payload['store_id'])) {
            return true;
        }
        try {
            $store = $this->storeManager->getStore((int) $payload['store_id']);
            $websiteId = (int) $store->getWebsiteId();
        } catch (NoSuchEntityException $e) {
            return true;
        }
        return in_array($websiteId, array_map('intval', $websiteIds), true);
    }

    /**
     * Atomic debounce: unique key on (workflow_id, entity_id, time_bucket)
     * makes concurrent consumers race-safe — duplicate key = debounced.
     * A SELECT-then-INSERT check would be racy; this is not.
     */
    private function passesDebounce(int $workflowId, int $entityId): bool
    {
        $window = (int) $this->scopeConfig->getValue(self::CONFIG_DEBOUNCE_WINDOW);
        if ($window <= 0) {
            $window = self::DEFAULT_DEBOUNCE_WINDOW;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::DEBOUNCE_TABLE);

        try {
            $connection->insert($table, [
                'workflow_id' => $workflowId,
                'entity_id' => $entityId,
                'time_bucket' => intdiv(time(), $window),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (AlreadyExistsException $e) {
            return false;
        } catch (\Magento\Framework\DB\Adapter\DuplicateException $e) {
            return false;
        } catch (\Exception $e) {
            if ($this->isDuplicateKeyException($e)) {
                return false;
            }
            throw $e;
        }
        return true;
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

    private function createExecution(
        WorkflowInterface $workflow,
        array $triggerPayload,
        int $entityId,
        int $chainDepth
    ): WorkflowExecutionInterface {
        $context = [
            'trigger' => $triggerPayload,
            'steps' => new \stdClass(),
            'workflow' => [
                'id' => (int) $workflow->getWorkflowId(),
                'name' => $workflow->getName(),
                'version' => $workflow->getVersion(),
                'entity_type' => $workflow->getEntityType(),
            ],
        ];

        /** @var WorkflowExecutionInterface $execution */
        $execution = $this->executionFactory->create();
        $execution->setUuid($this->generateUuidV4());
        $execution->setWorkflowId((int) $workflow->getWorkflowId());
        $execution->setWorkflowVersion($workflow->getVersion());
        $execution->setDefinitionSnapshot($workflow->getDefinition());
        $execution->setEntityId($entityId);
        $execution->setStoreId((int) ($triggerPayload['store_id'] ?? 0));
        $execution->setStatus(WorkflowExecutionInterface::STATUS_PENDING);
        $execution->setContext(json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $execution->setChainDepth($chainDepth);
        $execution->setCurrentStep(null);

        return $execution;
    }

    private function extractEntityId(array $payload): int
    {
        return (int) ($payload['entity_id'] ?? $payload['id'] ?? 0);
    }

    /**
     * RFC 4122 v4 UUID from random bytes
     */
    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
