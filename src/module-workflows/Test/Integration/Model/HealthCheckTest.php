<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\Health\HealthCheck;
use MageOS\Workflows\Model\WorkflowFactory;
use PHPUnit\Framework\TestCase;

/**
 * Plan #31 (docs/20-integration-test-plan.md §7): HealthCheck::runChecks()
 * against the real DB. Every check is independent (HealthCheck.php class
 * docblock); this suite seeds the exact degradation each DB-backed check
 * detects and pins both the healthy (green) and degraded read.
 *
 * Two of the six checks (`queue_backend`, `async_events_module`) read
 * deployment config / installed-module state rather than seedable table rows
 * — they are not "degradations to seed" in a DB-transaction sense, so this
 * suite only pins their result SHAPE (a well-formed ok/warn/fail + message),
 * not a specific value, since that value is a property of the CI
 * environment's env.php / composer install, not of fixture data. The
 * remaining four checks are fully DB-seedable and get both a green and a
 * red pin.
 *
 * No sleeps: every "stale" scenario rewinds a persisted timestamp with a
 * direct UPDATE after the row is inserted, per docs/20 §2.4.
 *
 * @magentoDbIsolation enabled
 */
class HealthCheckTest extends TestCase
{
    private HealthCheck $healthCheck;
    private ResourceConnection $resourceConnection;
    private WorkflowRepositoryInterface $workflowRepository;
    private WorkflowFactory $workflowFactory;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->healthCheck = $objectManager->get(HealthCheck::class);
        $this->resourceConnection = $objectManager->get(ResourceConnection::class);
        $this->workflowRepository = $objectManager->get(WorkflowRepositoryInterface::class);
        $this->workflowFactory = $objectManager->get(WorkflowFactory::class);

        // Deterministic baseline: no cron_schedule rows in this transaction,
        // so cron_alive / sweeper_scheduled start from a known "nothing has
        // run" state regardless of what a real cron daemon may have written
        // outside of this test's isolation.
        $this->clearCronSchedule();
    }

    public function testRunChecksReturnsAllSixCodesInDeclaredOrder(): void
    {
        $checks = $this->healthCheck->runChecks();
        $this->assertCount(6, $checks);

        $codes = array_column($checks, 'code');
        $this->assertSame([
            HealthCheck::CODE_QUEUE_BACKEND,
            HealthCheck::CODE_CRON_ALIVE,
            HealthCheck::CODE_SWEEPER_SCHEDULED,
            HealthCheck::CODE_STUCK_EXECUTIONS,
            HealthCheck::CODE_OVERDUE_RESUMES,
            HealthCheck::CODE_ASYNC_EVENTS_MODULE,
        ], $codes);

        foreach ($checks as $check) {
            $this->assertContains(
                $check['status'],
                [HealthCheck::STATUS_OK, HealthCheck::STATUS_WARN, HealthCheck::STATUS_FAIL],
                $check['code'] . ': status must be one of ok/warn/fail'
            );
            $this->assertNotSame('', $check['message'], $check['code'] . ': message must not be empty');
        }
    }

    public function testCronAliveIsOkWithARecentScheduleRowAndFailsWithNone(): void
    {
        $degraded = $this->checkFor(HealthCheck::CODE_CRON_ALIVE);
        $this->assertSame(HealthCheck::STATUS_FAIL, $degraded['status'], 'No cron_schedule rows at all: cron_alive must fail');

        $this->insertCronSchedule('any_job_code', '-1 minutes');
        $healthy = $this->checkFor(HealthCheck::CODE_CRON_ALIVE);
        $this->assertSame(HealthCheck::STATUS_OK, $healthy['status']);
    }

    public function testSweeperScheduledIsOkWithARecentSweeperRowAndWarnsWithNone(): void
    {
        // A recent row for an UNRELATED job code must not satisfy the
        // sweeper-specific check (it filters on job_code).
        $this->insertCronSchedule('some_other_job', '-1 minutes');
        $degraded = $this->checkFor(HealthCheck::CODE_SWEEPER_SCHEDULED);
        $this->assertSame(HealthCheck::STATUS_WARN, $degraded['status']);

        $this->insertCronSchedule('mageos_workflows_resume_sweeper', '-5 minutes');
        $healthy = $this->checkFor(HealthCheck::CODE_SWEEPER_SCHEDULED);
        $this->assertSame(HealthCheck::STATUS_OK, $healthy['status']);
    }

    public function testStuckExecutionsIsOkWithNoneAndFailsWithAStalePendingRow(): void
    {
        $healthy = $this->checkFor(HealthCheck::CODE_STUCK_EXECUTIONS);
        $this->assertSame(HealthCheck::STATUS_OK, $healthy['status']);

        $workflowId = $this->saveEnabledWorkflow('health check stuck execution fixture');
        $executionId = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_PENDING);
        $this->rewindTriggeredAt($executionId, '-15 minutes');

        $degraded = $this->checkFor(HealthCheck::CODE_STUCK_EXECUTIONS);
        $this->assertSame(HealthCheck::STATUS_FAIL, $degraded['status']);
        $this->assertStringContainsString('1 execution(s) pending', $degraded['message']);
    }

    public function testStuckExecutionsIgnoresARecentPendingRow(): void
    {
        $workflowId = $this->saveEnabledWorkflow('health check recent pending fixture');
        // Pending but within the stale window (default triggered_at = now).
        $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_PENDING);

        $result = $this->checkFor(HealthCheck::CODE_STUCK_EXECUTIONS);
        $this->assertSame(HealthCheck::STATUS_OK, $result['status'], 'A fresh pending execution is not "stuck" yet');
    }

    public function testOverdueResumesIsOkWithNoneAndFailsWithAStaleWaitingStep(): void
    {
        $healthy = $this->checkFor(HealthCheck::CODE_OVERDUE_RESUMES);
        $this->assertSame(HealthCheck::STATUS_OK, $healthy['status']);

        $workflowId = $this->saveEnabledWorkflow('health check overdue resume fixture');
        $executionId = $this->insertExecution($workflowId, WorkflowExecutionInterface::STATUS_WAITING);
        $stepId = $this->insertWaitingStep($executionId, '-15 minutes');
        $this->assertGreaterThan(0, $stepId);

        $degraded = $this->checkFor(HealthCheck::CODE_OVERDUE_RESUMES);
        $this->assertSame(HealthCheck::STATUS_FAIL, $degraded['status']);
        $this->assertStringContainsString('overdue for resume', $degraded['message']);
    }

    public function testQueueBackendAndAsyncEventsModuleReturnWellFormedResults(): void
    {
        foreach ([HealthCheck::CODE_QUEUE_BACKEND, HealthCheck::CODE_ASYNC_EVENTS_MODULE] as $code) {
            $result = $this->checkFor($code);
            $this->assertSame($code, $result['code']);
            $this->assertContains(
                $result['status'],
                [HealthCheck::STATUS_OK, HealthCheck::STATUS_WARN, HealthCheck::STATUS_FAIL]
            );
        }
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkFor(string $code): array
    {
        foreach ($this->healthCheck->runChecks() as $check) {
            if ($check['code'] === $code) {
                return $check;
            }
        }
        $this->fail("No check found for code \"$code\"");
    }

    private function saveEnabledWorkflow(string $name): int
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
                's1' => ['type' => 'action', 'action' => 'order.add_comment', 'config' => ['comment' => 'x'], 'next' => null],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return (int) $this->workflowRepository->save($workflow)->getWorkflowId();
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
            'waiting_event' => $status === WorkflowExecutionInterface::STATUS_WAITING ? 'sales.order.updated' : null,
        ]);
        return (int) $connection->lastInsertId($table);
    }

    private function insertWaitingStep(int $executionId, string $resumeAtRelative): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('mageos_workflow_execution_step');
        $connection->insert($table, [
            'execution_id' => $executionId,
            'step_key' => 's1',
            'status' => WorkflowExecutionStepInterface::STATUS_WAITING,
            'resume_at' => (new \DateTime($resumeAtRelative, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
        return (int) $connection->lastInsertId($table);
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

    private function clearCronSchedule(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');
        $connection->delete($table);
    }

    private function insertCronSchedule(string $jobCode, string $scheduledAtRelative): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cron_schedule');
        $connection->insert($table, [
            'job_code' => $jobCode,
            'status' => 'success',
            'created_at' => (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'scheduled_at' => (new \DateTime($scheduledAtRelative, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

    private function randomUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
