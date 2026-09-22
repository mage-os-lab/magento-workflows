<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Console;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowExecutionRepositoryInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Console\Command\RunCommand;
use MageOS\Workflows\Console\Command\StatsCommand;
use MageOS\Workflows\Model\WorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Plan #29 (docs/20-integration-test-plan.md §7): `workflow:run` dispatches a
 * real execution for a real entity (trigger_type=manual per docs/05), and
 * `workflow:stats` reads seeded executions/steps directly out of the real DB.
 * Both via CommandTester with commands built from the object manager.
 *
 * @magentoDbIsolation enabled
 */
class RunStatsCommandsTest extends TestCase
{
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowExecutionRepositoryInterface $executionRepository;
    private WorkflowFactory $workflowFactory;
    private ResourceConnection $resourceConnection;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->executionRepository = $objectManager->get(WorkflowExecutionRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testWorkflowRunDispatchesARealExecutionForARealOrder(): void
    {
        $order = $this->fixtureOrder();
        $workflowId = $this->saveEnabledLinearWorkflow('workflow:run fixture');

        $tester = new CommandTester(Bootstrap::getObjectManager()->get(RunCommand::class));
        $exitCode = $tester->execute([
            'workflow_id' => (string) $workflowId,
            '--entity-id' => (string) $order->getId(),
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $uuid = trim($tester->getDisplay());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/i',
            $uuid,
            'workflow:run must print the dispatched execution UUID'
        );

        $execution = $this->executionRepository->getByUuid($uuid);
        $this->assertSame($workflowId, $execution->getWorkflowId());
        $this->assertSame((int) $order->getId(), $execution->getEntityId());
        $this->assertSame(WorkflowInterface::TRIGGER_TYPE_MANUAL, $execution->getTriggerType());
    }

    public function testWorkflowRunRequiresANumericEntityId(): void
    {
        $workflowId = $this->saveEnabledLinearWorkflow('workflow:run missing entity id');

        $tester = new CommandTester(Bootstrap::getObjectManager()->get(RunCommand::class));
        $exitCode = $tester->execute(['workflow_id' => (string) $workflowId]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--entity-id is required and must be numeric', $tester->getDisplay());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testWorkflowRunDryRunPreviewsWithoutCreatingAnExecution(): void
    {
        $order = $this->fixtureOrder();
        $workflowId = $this->saveEnabledLinearWorkflow('workflow:run dry-run fixture');
        $before = $this->countExecutionsFor($workflowId);

        $tester = new CommandTester(Bootstrap::getObjectManager()->get(RunCommand::class));
        $exitCode = $tester->execute([
            'workflow_id' => (string) $workflowId,
            '--entity-id' => (string) $order->getId(),
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
        $this->assertSame($before, $this->countExecutionsFor($workflowId), '--dry-run must not persist an execution');
    }

    public function testWorkflowStatsReadsSeededExecutionCountsAndWaitingSteps(): void
    {
        $workflowId = $this->saveEnabledLinearWorkflow('workflow:stats fixture');

        // Two "complete" executions: one recent (counts in the last-24h
        // column), one old (counted only in the all-time total). One
        // "waiting" execution with a waiting step row.
        $recentId = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_COMPLETE);
        $this->rewindTriggeredAt($recentId, '-2 hours');

        $oldId = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_COMPLETE);
        $this->rewindTriggeredAt($oldId, '-48 hours');

        $waitingExecutionId = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_WAITING);
        $this->insertStep($waitingExecutionId, WorkflowExecutionStepInterface::STATUS_WAITING);

        $tester = new CommandTester(Bootstrap::getObjectManager()->get(StatsCommand::class));
        $exitCode = $tester->execute(['--workflow-id' => (string) $workflowId]);
        $this->assertSame(0, $exitCode, $tester->getDisplay());

        $display = $tester->getDisplay();
        $this->assertStringContainsString((string) $workflowId, $display);
        $this->assertStringContainsString(WorkflowExecutionInterface::STATUS_COMPLETE, $display);
        $this->assertStringContainsString(WorkflowExecutionInterface::STATUS_WAITING, $display);
        // 2 total "complete" rows, only 1 within the last 24h.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(WorkflowExecutionInterface::STATUS_COMPLETE, '/') . '\s*\|\s*2\s*\|\s*1/',
            $display,
            'Expected the complete row to show total=2, last24h=1: ' . $display
        );
        $this->assertStringContainsString('Waiting steps', $display);
    }

    private function saveEnabledLinearWorkflow(string $name): int
    {
        $workflow = $this->workflowFactory->create();
        $workflow->setName($name);
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition(json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'workflow:run / workflow:stats fixture'],
                    'next' => null,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $saved = $this->workflowRepository->save($workflow);
        return (int) $saved->getWorkflowId();
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId(), 'Magento/Sales/_files/order.php fixture must exist');
        return $order;
    }

    private function countExecutionsFor(int $workflowId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow_execution');
        return (int) $connection->fetchOne(
            $connection->select()->from($table, 'COUNT(*)')->where('workflow_id = ?', $workflowId)
        );
    }

    private function insertExecution(int $workflowId, string $status): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow_execution');
        $connection->insert($table, [
            'uuid' => $this->randomUuid(),
            'workflow_id' => $workflowId,
            'workflow_version' => 1,
            'definition_snapshot' => '{"schema":1,"entry":"s1","steps":{"s1":{"type":"action","action":"order.add_comment","config":{},"next":null}}}',
            'entity_id' => 1,
            'store_id' => 1,
            'status' => $status,
        ]);
        return (int) $connection->lastInsertId($table);
    }

    private function insertStep(int $executionId, string $status): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow_execution_step');
        $connection->insert($table, [
            'execution_id' => $executionId,
            'step_key' => 's1',
            'status' => $status,
        ]);
    }

    private function rewindTriggeredAt(int $executionId, string $relative): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow_execution');
        $connection->update(
            $table,
            ['triggered_at' => (new \DateTime($relative, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['execution_id = ?' => $executionId]
        );
    }

    private function randomUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
