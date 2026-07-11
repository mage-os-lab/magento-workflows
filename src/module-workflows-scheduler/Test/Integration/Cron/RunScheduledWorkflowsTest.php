<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Integration\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\Workflows\Model\WorkflowFactory;
use MageOS\WorkflowsScheduler\Cron\RunScheduledWorkflows;
use MageOS\WorkflowsScheduler\Model\QueryRunner;
use MageOS\WorkflowsScheduler\Test\Integration\_files\RecordingQueryRunner;
use PHPUnit\Framework\TestCase;

/**
 * Plan #24a (docs/20-integration-test-plan.md §6): store-timezone cron
 * evaluation and the double-fire guard in RunScheduledWorkflows, against real
 * MySQL and the real WorkflowIndex/WorkflowRepository. QueryRunner is replaced
 * with a recording double so a "due" workflow is observable as exactly one
 * hand-off per wall-clock minute, without needing matching entities or a real
 * dispatch. Timestamps are rewound with a direct UPDATE (no sleeps, §2.4).
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class RunScheduledWorkflowsTest extends TestCase
{
    private const STATE_TABLE = 'mageos_workflow_schedule_state';

    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private ResourceConnection $resource;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->resource = $this->objectManager->get(ResourceConnection::class);
    }

    /**
     * A workflow due this minute dispatches exactly once; a second cron tick in
     * the same minute is guarded by mageos_workflow_schedule_state; once the
     * recorded minute is in the past the workflow re-arms.
     */
    public function testDueScheduleDispatchesOnceThenReArmsNextMinute(): void
    {
        $runner = $this->configureRecordingRunner();
        $workflow = $this->createScheduleWorkflow('* * * * *', []);
        $workflowId = (int) $workflow->getWorkflowId();

        $cron = $this->objectManager->create(RunScheduledWorkflows::class);

        $cron->execute();
        $this->assertSame(1, $runner->runCount(), 'A due schedule hands off to QueryRunner once');
        $this->assertSame([$workflowId], $runner->runWorkflowIds());
        $this->assertNotNull($this->loadState($workflowId), 'The last-run state row is written');

        // Second tick within the same minute: the double-fire guard blocks it.
        $cron->execute();
        $this->assertSame(1, $runner->runCount(), 'A second tick in the same minute must not re-fire');

        // Rewind the recorded minute into the past: the guard releases.
        $this->rewindLastRun($workflowId);
        $cron->execute();
        $this->assertSame(2, $runner->runCount(), 'A later minute re-arms the schedule');
    }

    /**
     * A non-due cron expression is never handed off.
     */
    public function testNonDueScheduleDoesNotDispatch(): void
    {
        $runner = $this->configureRecordingRunner();
        // 31 February never occurs, so isDue() is false every minute.
        $this->createScheduleWorkflow('0 0 31 2 *', []);

        $this->objectManager->create(RunScheduledWorkflows::class)->execute();

        $this->assertSame(0, $runner->runCount(), 'A never-due schedule must not dispatch');
    }

    /**
     * Broken store timezone tripwire (docs/19 risk note,
     * RunScheduledWorkflows.php:83-93 / 134-153): a store whose configured
     * timezone does not resolve degrades to a working clock (a warning is
     * logged) rather than aborting the tick — the workflow still evaluates and
     * fires. Pins the current silent-degrade behavior so a future fix is
     * visible here.
     *
     * @magentoConfigFixture default_store general/locale/timezone Not/AZone
     */
    public function testBrokenStoreTimezoneDegradesButStillProcesses(): void
    {
        $runner = $this->configureRecordingRunner();
        $this->createScheduleWorkflow('* * * * *', [1]);

        $this->objectManager->create(RunScheduledWorkflows::class)->execute();

        $this->assertSame(
            1,
            $runner->runCount(),
            'A broken store timezone must degrade to a default clock, not abort the tick'
        );
    }

    private function configureRecordingRunner(): RecordingQueryRunner
    {
        $this->objectManager->configure([
            'preferences' => [QueryRunner::class => RecordingQueryRunner::class],
        ]);
        /** @var RecordingQueryRunner $runner */
        $runner = $this->objectManager->get(QueryRunner::class);
        return $runner;
    }

    /**
     * @param int[] $websiteIds
     */
    private function createScheduleWorkflow(string $cron, array $websiteIds): WorkflowInterface
    {
        $workflow = $this->objectManager->get(WorkflowFactory::class)->create();
        $workflow->setName('scheduler cron ' . $cron);
        $workflow->setStatus(WorkflowInterface::STATUS_ENABLED);
        $workflow->setTriggerType(WorkflowInterface::TRIGGER_TYPE_SCHEDULE);
        $workflow->setTriggerRef($cron);
        $workflow->setEntityType('sales_order');
        $workflow->setDefinition((string) json_encode([
            'schema' => 1,
            'entry' => 's1',
            'steps' => [
                's1' => [
                    'type' => 'action',
                    'action' => 'order.add_comment',
                    'config' => ['comment' => 'scheduled'],
                    'next' => null,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($websiteIds !== []) {
            $workflow->setWebsiteIds($websiteIds);
        }

        return $this->objectManager->get(WorkflowRepositoryInterface::class)->save($workflow);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadState(int $workflowId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::STATE_TABLE))
                ->where('workflow_id = ?', $workflowId)
        );
        return $row ?: null;
    }

    private function rewindLastRun(int $workflowId): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName(self::STATE_TABLE),
            ['last_run_at' => '2000-01-01 00:00:00'],
            ['workflow_id = ?' => $workflowId]
        );
    }
}
