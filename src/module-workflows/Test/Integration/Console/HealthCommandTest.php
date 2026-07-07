<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Console;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Console\Command\HealthCommand;
use MageOS\Workflows\Model\WorkflowFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Plan #31 (docs/20-integration-test-plan.md §7): `bin/magento workflow:health`
 * renders HealthCheck::runChecks() as a table and its exit code is 1 iff any
 * check is 'fail' (HealthCommand.php class docblock), via CommandTester with
 * the command built from the object manager.
 *
 * @magentoDbIsolation enabled
 */
class HealthCommandTest extends TestCase
{
    private ResourceConnection $resourceConnection;
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowFactory $workflowFactory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);
    }

    public function testCommandPrintsATableWithEverySixCheckCodes(): void
    {
        $tester = new CommandTester(Bootstrap::getObjectManager()->get(HealthCommand::class));
        $tester->execute([]);

        $display = $tester->getDisplay();
        foreach ([
            'queue_backend',
            'cron_alive',
            'sweeper_scheduled',
            'stuck_executions',
            'overdue_resumes',
            'async_events_module',
        ] as $code) {
            $this->assertStringContainsString($code, $display, "Expected the $code row in the table");
        }
    }

    public function testCommandFailsExitCodeAndPrintsErrorSummaryWhenAStuckExecutionExists(): void
    {
        $workflow = $this->workflowFactory->create();
        $workflow->setName('health command stuck execution fixture');
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_EVENT);
        $workflow->setTriggerRef('sales.order.created');
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition(json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => ['s1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $workflowId = (int) $this->workflowRepository->save($workflow)->getWorkflowId();

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow_execution');
        $connection->insert($table, [
            'uuid' => $this->randomUuid(),
            'workflow_id' => $workflowId,
            'workflow_version' => 1,
            'definition_snapshot' => '{"schema":1,"entry":"s1","steps":{}}',
            'entity_id' => 1,
            'store_id' => 1,
            'status' => WorkflowExecutionInterface::STATUS_PENDING,
        ]);
        $executionId = (int) $connection->lastInsertId($table);
        $connection->update(
            $table,
            ['triggered_at' => (new \DateTime('-20 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['execution_id = ?' => $executionId]
        );

        $tester = new CommandTester(Bootstrap::getObjectManager()->get(HealthCommand::class));
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('FAIL', $display);
        // Symfony's error block hard-wraps the summary across lines and pads
        // with spaces, so collapse all whitespace before matching the message.
        $normalized = (string) preg_replace('/\s+/', ' ', $display);
        $this->assertStringContainsString(
            'One or more workflow health checks failed. See docs/15-operations.md for remediation.',
            $normalized
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
