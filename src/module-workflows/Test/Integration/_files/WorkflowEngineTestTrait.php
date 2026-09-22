<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\_files;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterfaceFactory;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Action\ActionPool;
use MageOS\Workflows\Model\ResourceModel\Workflow as WorkflowResource;
use MageOS\Workflows\Model\Workflow as WorkflowModel;
use MageOS\Workflows\Model\WorkflowFactory;

/**
 * Shared engine-spine helpers (docs/20 §2.4) for the Phase B integration
 * suites (#3-14): workflow creation through the real save path, direct
 * resource-model inserts that bypass save-time validation, manual execution
 * seeding, the seeded-order lookup, and timestamp rewinding (no sleeps).
 *
 * Not a test — a trait mixed into the suites, autoloaded via the module PSR-4
 * root (MageOS\Workflows\ => module dir).
 */
trait WorkflowEngineTestTrait
{
    protected function om(): ObjectManagerInterface
    {
        return Bootstrap::getObjectManager();
    }

    protected function resource(): ResourceConnection
    {
        return $this->om()->get(ResourceConnection::class);
    }

    protected function db(): AdapterInterface
    {
        return $this->resource()->getConnection();
    }

    protected function table(string $name): string
    {
        return $this->resource()->getTableName($name);
    }

    /**
     * Create a workflow through the real repository save path (transits
     * ValidateWorkflowOnSave — definitions must be structurally valid with real
     * action codes).
     *
     * @param array{
     *   name?: string, definition: array, status?: int, conditions?: ?string,
     *   entityType?: string, triggerType?: string, triggerRef?: string,
     *   websiteIds?: int[], loopGuardDepth?: int
     * } $args
     */
    protected function createWorkflow(array $args): WorkflowInterface
    {
        $factory = $this->om()->get(WorkflowFactory::class);
        /** @var WorkflowInterface $workflow */
        $workflow = $factory->create();
        $workflow->setName($args['name'] ?? 'Engine fixture ' . uniqid('', true));
        $workflow->setStatus($args['status'] ?? WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType($args['triggerType'] ?? WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef($args['triggerRef'] ?? 'sales.order.created');
        $workflow->setEntityType($args['entityType'] ?? 'sales_order');
        $workflow->setDefinition(
            json_encode($args['definition'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        if (array_key_exists('conditions', $args)) {
            $workflow->setConditionsSerialized($args['conditions']);
        }
        if (isset($args['loopGuardDepth'])) {
            $workflow->setLoopGuardDepth($args['loopGuardDepth']);
        }
        if (isset($args['websiteIds'])) {
            $workflow->setWebsiteIds($args['websiteIds']);
        }

        return $this->om()->get(WorkflowRepositoryInterface::class)->save($workflow);
    }

    /**
     * Persist a workflow row DIRECTLY through the resource model, bypassing the
     * repository's ValidateWorkflowOnSave plugin. Used to stage definitions the
     * validator would reject (unknown action codes, cyclic graphs) so the
     * runtime behavior past validation can be pinned.
     *
     * @param array<string, mixed> $data column => value (definition may be array or JSON string)
     * @return int the new workflow id
     */
    protected function insertWorkflowRow(array $data): int
    {
        $factory = $this->om()->get(WorkflowFactory::class);
        $resource = $this->om()->get(WorkflowResource::class);
        /** @var WorkflowModel $model */
        $model = $factory->create();
        $definition = $data['definition'] ?? [];
        $data['definition'] = is_array($definition)
            ? json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : (string) $definition;
        $model->setData(array_merge([
            'name' => 'raw fixture ' . uniqid('', true),
            'status' => WorkflowInterface::STATUS_ENABLED,
            'trigger_type' => WorkflowInterface::TRIGGER_TYPE_EVENT,
            'trigger_ref' => 'sales.order.created',
            'entity_type' => 'sales_order',
            'version' => 1,
            'loop_guard_depth' => 1,
        ], $data));
        $resource->save($model);

        return (int) $model->getId();
    }

    /**
     * Seed a fresh pending execution row pinned to the given definition
     * snapshot (bypasses the dispatcher's guards; the executor is the SUT).
     *
     * @param array $trigger trigger snapshot for the context bag
     */
    protected function seedExecution(
        int $workflowId,
        string $definitionSnapshotJson,
        int $entityId,
        int $storeId,
        array $trigger = [],
        int $workflowVersion = 1,
        string $status = WorkflowExecutionInterface::STATUS_PENDING
    ): WorkflowExecutionInterface {
        $factory = $this->om()->get(WorkflowExecutionInterfaceFactory::class);
        /** @var WorkflowExecutionInterface $execution */
        $execution = $factory->create();
        $execution->setUuid($this->uuid());
        $execution->setWorkflowId($workflowId);
        $execution->setWorkflowVersion($workflowVersion);
        $execution->setDefinitionSnapshot($definitionSnapshotJson);
        $execution->setEntityId($entityId);
        $execution->setStoreId($storeId);
        $execution->setStatus($status);
        $execution->setTriggerType(WorkflowInterface::TRIGGER_TYPE_MANUAL);
        $execution->setChainDepth(0);
        $execution->setCurrentStep(null);
        $execution->setContext(json_encode([
            'trigger' => $trigger === [] ? new \stdClass() : $trigger,
            'steps' => new \stdClass(),
            'workflow' => ['id' => $workflowId, 'version' => $workflowVersion, 'entity_type' => 'sales_order'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->om()->get(WorkflowExecutionRepositoryInterface::class)->save($execution);
    }

    protected function reloadExecution(int $executionId): WorkflowExecutionInterface
    {
        return $this->om()->get(WorkflowExecutionRepositoryInterface::class)->getById($executionId);
    }

    protected function reloadWorkflow(int $workflowId): WorkflowInterface
    {
        return $this->om()->get(WorkflowRepositoryInterface::class)->getById($workflowId);
    }

    /**
     * @return array<int, array<string, mixed>> step rows ordered by id
     */
    protected function stepRows(int $executionId): array
    {
        $connection = $this->db();
        $table = $this->table('mageos_workflow_execution_step');
        return $connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('execution_id = ?', $executionId)
                ->order('step_execution_id ASC')
        );
    }

    /**
     * @return array<int, string> step_key list in row-id order
     */
    protected function stepKeys(int $executionId): array
    {
        return array_map(static fn (array $r): string => (string) $r['step_key'], $this->stepRows($executionId));
    }

    /**
     * @return array<string, string> step_key => status
     */
    protected function stepStatuses(int $executionId): array
    {
        $out = [];
        foreach ($this->stepRows($executionId) as $row) {
            $out[(string) $row['step_key']] = (string) $row['status'];
        }
        return $out;
    }

    /**
     * The order seeded by Magento/Sales/_files/order.php (increment id 100000001).
     */
    protected function seededOrder(): Order
    {
        /** @var Order $order */
        $order = $this->om()->create(Order::class)->loadByIncrementId('100000001');
        if (!$order->getId()) {
            $this->fail('Expected the Magento/Sales/_files/order.php fixture order (100000001) to be present');
        }
        return $order;
    }

    /**
     * Build an ActionPool over the real DI-registered actions with the given
     * code overrides — lets a test observe/steer the executor without a
     * test-scoped di.xml (forbidden by the plan's file boundary).
     *
     * @param array<string, \MageOS\Workflows\Api\ActionInterface> $overrides code => action
     */
    protected function actionPoolWith(array $overrides): ActionPool
    {
        $base = $this->om()->get(ActionPool::class)->getAll();
        return new ActionPool(array_merge($base, $overrides));
    }

    protected function rewindTimestamp(string $tableName, string $column, string $value, string $whereColumn, mixed $whereValue): void
    {
        $connection = $this->db();
        $connection->update(
            $this->table($tableName),
            [$column => $value],
            [$whereColumn . ' = ?' => $whereValue]
        );
    }

    protected function gmPast(int $secondsAgo): string
    {
        return gmdate('Y-m-d H:i:s', time() - $secondsAgo);
    }

    protected function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
