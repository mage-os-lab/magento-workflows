<?php

declare(strict_types=1);

namespace MageOS\Workflows\Model\Health;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\Workflows\Api\Data\WorkflowExecutionInterface;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Operational health checks for the "first-run silently does nothing" gap
 * (docs/15-operations.md): a default install queues executions but has no
 * RabbitMQ, no running consumers, and possibly no cron — nothing tells the
 * operator. `runChecks()` surfaces that state for both `bin/magento
 * workflow:health` (Console\Command\HealthCommand) and the admin grid notice
 * (module-workflows-admin-ui Block\Adminhtml\Health).
 *
 * Every check is independent and defensive: a check that hits the database
 * catches its own exceptions (e.g. tables absent before setup:upgrade has
 * run) and degrades to 'warn' rather than throwing, so one broken check
 * never prevents the others from reporting.
 */
class HealthCheck
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    public const CODE_QUEUE_BACKEND = 'queue_backend';
    public const CODE_CRON_ALIVE = 'cron_alive';
    public const CODE_SWEEPER_SCHEDULED = 'sweeper_scheduled';
    public const CODE_STUCK_EXECUTIONS = 'stuck_executions';
    public const CODE_OVERDUE_RESUMES = 'overdue_resumes';
    public const CODE_ASYNC_EVENTS_MODULE = 'async_events_module';

    private const CRON_SCHEDULE_TABLE = 'cron_schedule';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const EXECUTION_STEP_TABLE = 'mageos_workflow_execution_step';

    private const SWEEPER_JOB_CODE = 'mageos_workflows_resume_sweeper';
    private const ASYNC_EVENTS_MODULE = 'MageOS_AsyncEvents';

    private const CRON_ALIVE_WINDOW_MINUTES = 15;
    private const SWEEPER_WINDOW_MINUTES = 30;
    private const STALE_THRESHOLD_MINUTES = 10;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly ModuleManager $moduleManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, array{code: string, status: string, message: string}>
     */
    public function runChecks(): array
    {
        return [
            $this->checkQueueBackend(),
            $this->checkCronAlive(),
            $this->checkSweeperScheduled(),
            $this->checkStuckExecutions(),
            $this->checkOverdueResumes(),
            $this->checkAsyncEventsModule(),
        ];
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkQueueBackend(): array
    {
        try {
            $host = $this->deploymentConfig->get('queue/amqp/host');
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Workflow health check queue_backend could not read deployment config: %s', $e->getMessage()),
                ['exception' => $e]
            );
            $host = null;
        }

        if ($host !== null && $host !== '') {
            return $this->result(self::CODE_QUEUE_BACKEND, self::STATUS_OK, 'RabbitMQ configured');
        }

        return $this->result(
            self::CODE_QUEUE_BACKEND,
            self::STATUS_WARN,
            'No RabbitMQ; ensure consumers use the db connection (see docs/15-operations.md) '
            . 'and cron runs consumers'
        );
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkCronAlive(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::CRON_SCHEDULE_TABLE);
            $since = $this->minutesAgo(self::CRON_ALIVE_WINDOW_MINUTES);

            $select = $connection->select()
                ->from($table, ['count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('scheduled_at >= ?', $since);
            $count = (int) $connection->fetchOne($select);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Workflow health check cron_alive could not query cron_schedule: %s', $e->getMessage()),
                ['exception' => $e]
            );
            return $this->result(
                self::CODE_CRON_ALIVE,
                self::STATUS_WARN,
                'Could not read cron_schedule (table may not exist yet); run setup:upgrade'
            );
        }

        if ($count > 0) {
            return $this->result(self::CODE_CRON_ALIVE, self::STATUS_OK, 'Magento cron is running');
        }

        return $this->result(
            self::CODE_CRON_ALIVE,
            self::STATUS_FAIL,
            'Magento cron does not appear to be running'
        );
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkSweeperScheduled(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::CRON_SCHEDULE_TABLE);
            $since = $this->minutesAgo(self::SWEEPER_WINDOW_MINUTES);

            $select = $connection->select()
                ->from($table, ['count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('job_code = ?', self::SWEEPER_JOB_CODE)
                ->where('scheduled_at >= ?', $since);
            $count = (int) $connection->fetchOne($select);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf('Workflow health check sweeper_scheduled could not query cron_schedule: %s', $e->getMessage()),
                ['exception' => $e]
            );
            return $this->result(
                self::CODE_SWEEPER_SCHEDULED,
                self::STATUS_WARN,
                'Could not read cron_schedule (table may not exist yet); run setup:upgrade'
            );
        }

        if ($count > 0) {
            return $this->result(
                self::CODE_SWEEPER_SCHEDULED,
                self::STATUS_OK,
                'Resume sweeper cron job is scheduled'
            );
        }

        return $this->result(
            self::CODE_SWEEPER_SCHEDULED,
            self::STATUS_WARN,
            'Resume sweeper (mageos_workflows_resume_sweeper) has not run recently; '
            . 'the "default" cron group may not be running, or module cron has not been registered yet'
        );
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkStuckExecutions(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
            $cutoff = $this->minutesAgo(self::STALE_THRESHOLD_MINUTES);

            $select = $connection->select()
                ->from($table, ['count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('status = ?', WorkflowExecutionInterface::STATUS_PENDING)
                ->where('triggered_at < ?', $cutoff);
            $count = (int) $connection->fetchOne($select);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Workflow health check stuck_executions could not query %s: %s',
                    self::EXECUTION_TABLE,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return $this->result(
                self::CODE_STUCK_EXECUTIONS,
                self::STATUS_WARN,
                'Could not read mageos_workflow_execution (table may not exist yet); run setup:upgrade'
            );
        }

        if ($count === 0) {
            return $this->result(self::CODE_STUCK_EXECUTIONS, self::STATUS_OK, 'No stuck pending executions');
        }

        return $this->result(
            self::CODE_STUCK_EXECUTIONS,
            self::STATUS_FAIL,
            sprintf(
                '%d execution(s) pending >%d min: mageos.workflow.execute consumer is not running',
                $count,
                self::STALE_THRESHOLD_MINUTES
            )
        );
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkOverdueResumes(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName(self::EXECUTION_STEP_TABLE);
            $cutoff = $this->minutesAgo(self::STALE_THRESHOLD_MINUTES);

            $select = $connection->select()
                ->from($table, ['count' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('status = ?', WorkflowExecutionStepInterface::STATUS_WAITING)
                ->where('resume_at IS NOT NULL')
                ->where('resume_at < ?', $cutoff);
            $count = (int) $connection->fetchOne($select);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Workflow health check overdue_resumes could not query %s: %s',
                    self::EXECUTION_STEP_TABLE,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return $this->result(
                self::CODE_OVERDUE_RESUMES,
                self::STATUS_WARN,
                'Could not read mageos_workflow_execution_step (table may not exist yet); run setup:upgrade'
            );
        }

        if ($count === 0) {
            return $this->result(self::CODE_OVERDUE_RESUMES, self::STATUS_OK, 'No overdue delay resumes');
        }

        return $this->result(
            self::CODE_OVERDUE_RESUMES,
            self::STATUS_FAIL,
            sprintf(
                '%d step(s) overdue for resume >%d min: neither the mageos.workflow.resume consumer nor the '
                . 'mageos_workflows_resume_sweeper cron job is processing delay steps',
                $count,
                self::STALE_THRESHOLD_MINUTES
            )
        );
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function checkAsyncEventsModule(): array
    {
        try {
            $enabled = $this->moduleManager->isEnabled(self::ASYNC_EVENTS_MODULE);
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Workflow health check async_events_module could not check module status: %s',
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return $this->result(
                self::CODE_ASYNC_EVENTS_MODULE,
                self::STATUS_WARN,
                'Could not determine whether MageOS_AsyncEvents is enabled'
            );
        }

        if ($enabled) {
            return $this->result(
                self::CODE_ASYNC_EVENTS_MODULE,
                self::STATUS_OK,
                'MageOS_AsyncEvents module is enabled'
            );
        }

        return $this->result(
            self::CODE_ASYNC_EVENTS_MODULE,
            self::STATUS_FAIL,
            'MageOS_AsyncEvents module is disabled: event triggers will not fire'
        );
    }

    /**
     * @return array{code: string, status: string, message: string}
     */
    private function result(string $code, string $status, string $message): array
    {
        return ['code' => $code, 'status' => $status, 'message' => $message];
    }

    private function minutesAgo(int $minutes): string
    {
        return gmdate('Y-m-d H:i:s', time() - $minutes * 60);
    }
}
