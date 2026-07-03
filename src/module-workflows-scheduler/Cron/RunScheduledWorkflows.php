<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Cron;

use Cron\CronExpression;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Workflows\Api\Data\WorkflowInterface;
use MageOS\Workflows\Api\WorkflowRepositoryInterface;
use MageOS\WorkflowsScheduler\Model\QueryRunner;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute (etc/crontab.xml, job mageos_workflows_scheduler).
 *
 * Loads every enabled/shadow schedule-type workflow, evaluates its cron
 * expression (trigger_ref) against the workflow's *store* timezone - "the
 * #1 support-ticket generator in every scheduler ever shipped"
 * (docs/14-risks.md) - and hands due workflows off to QueryRunner for
 * matching + dispatch. A tiny state table (mageos_workflow_schedule_state)
 * tracks the last fire minute per workflow so a slow run or an overlapping
 * cron tick can't double-fire the same workflow within one minute, plus the
 * watermark QueryRunner needs to avoid reprocessing rows.
 */
class RunScheduledWorkflows
{
    private const STATE_TABLE = 'mageos_workflow_schedule_state';

    public function __construct(
        private readonly WorkflowRepositoryInterface $workflowRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly ResourceConnection $resourceConnection,
        private readonly QueryRunner $queryRunner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->getScheduleWorkflows() as $workflow) {
            try {
                $this->processWorkflow($workflow);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'RunScheduledWorkflows: workflow #%d failed: %s',
                    (int) $workflow->getWorkflowId(),
                    $e->getMessage()
                ), ['exception' => $e]);
            }
        }
    }

    private function processWorkflow(WorkflowInterface $workflow): void
    {
        $workflowId = (int) $workflow->getWorkflowId();
        $timezoneCode = $this->resolveTimezone($workflow);

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($timezoneCode));
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                'RunScheduledWorkflows: unknown timezone "%s" for workflow #%d, using UTC: %s',
                $timezoneCode,
                $workflowId,
                $e->getMessage()
            ));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        try {
            $cron = new CronExpression($workflow->getTriggerRef());
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'RunScheduledWorkflows: workflow #%d has an invalid cron expression "%s": %s',
                $workflowId,
                $workflow->getTriggerRef(),
                $e->getMessage()
            ));
            return;
        }

        if (!$cron->isDue($now)) {
            return;
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $state = $this->loadState($workflowId);

        if ($state !== null && $state['last_run_at'] !== null) {
            $lastRunMinute = substr((string) $state['last_run_at'], 0, 16);
            if ($lastRunMinute === $nowUtc->format('Y-m-d H:i')) {
                // Already fired this workflow within the current wall-clock minute - skip.
                return;
            }
        }

        $watermark = $state['last_run_watermark'] ?? null;
        $newWatermark = $this->queryRunner->run($workflow, $watermark);

        $this->saveState($workflowId, $nowUtc->format('Y-m-d H:i:s'), $newWatermark);
    }

    /**
     * Store timezone of the workflow's first website's default store, per
     * docs/05-triggers.md#scheduled-triggers-workflows-scheduler. Falls back
     * to the config-default timezone when the workflow has no website scope
     * or the store/website can't be resolved.
     */
    private function resolveTimezone(WorkflowInterface $workflow): string
    {
        foreach ($workflow->getWebsiteIds() as $websiteId) {
            try {
                $store = $this->storeManager->getWebsite((int) $websiteId)->getDefaultStore();
                if ($store !== null && (int) $store->getId() > 0) {
                    return $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (int) $store->getId());
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'RunScheduledWorkflows: could not resolve timezone via website #%d for workflow #%d: %s',
                    (int) $websiteId,
                    (int) $workflow->getWorkflowId(),
                    $e->getMessage()
                ));
            }
        }

        return $this->timezone->getConfigTimezone();
    }

    /**
     * @return WorkflowInterface[]
     */
    private function getScheduleWorkflows(): array
    {
        $triggerFilter = $this->filterBuilder
            ->setField(WorkflowInterface::TRIGGER_TYPE)
            ->setValue(WorkflowInterface::TRIGGER_TYPE_SCHEDULE)
            ->setConditionType('eq')
            ->create();

        $statusFilter = $this->filterBuilder
            ->setField(WorkflowInterface::STATUS)
            ->setValue([WorkflowInterface::STATUS_ENABLED, WorkflowInterface::STATUS_SHADOW])
            ->setConditionType('in')
            ->create();

        $triggerGroup = $this->filterGroupBuilder->setFilters([$triggerFilter])->create();
        $statusGroup = $this->filterGroupBuilder->setFilters([$statusFilter])->create();

        $criteria = $this->searchCriteriaBuilder
            ->setFilterGroups([$triggerGroup, $statusGroup])
            ->create();

        return array_values($this->workflowRepository->getList($criteria)->getItems());
    }

    /**
     * @return array{last_run_at: ?string, last_run_watermark: ?string}|null
     */
    private function loadState(int $workflowId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::STATE_TABLE);
        $select = $connection->select()
            ->from($table, ['last_run_at', 'last_run_watermark'])
            ->where('workflow_id = ?', $workflowId);
        $row = $connection->fetchRow($select);
        return $row ?: null;
    }

    private function saveState(int $workflowId, string $lastRunAt, ?string $watermark): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::STATE_TABLE);
        $connection->insertOnDuplicate(
            $table,
            [
                'workflow_id' => $workflowId,
                'last_run_at' => $lastRunAt,
                'last_run_watermark' => $watermark,
            ],
            ['last_run_at', 'last_run_watermark']
        );
    }
}
