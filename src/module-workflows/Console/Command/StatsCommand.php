<?php

declare(strict_types=1);

namespace MageOS\Workflows\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use MageOS\Workflows\Api\Data\WorkflowExecutionStepInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints execution counts grouped by workflow_id + status, last-24h volume, and the
 * count of steps currently parked "waiting" (delay steps pending resume).
 *
 * Queries mageos_workflow_execution / mageos_workflow_execution_step directly via
 * ResourceConnection rather than the repository layer, since this is an aggregate
 * operational report, not entity CRUD.
 */
class StatsCommand extends Command
{
    private const OPT_WORKFLOW_ID = 'workflow-id';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const EXECUTION_STEP_TABLE = 'mageos_workflow_execution_step';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('workflow:stats')
            ->setDescription(
                'Print workflow execution counts by status, last-24h volume, and waiting-step counts.'
            )
            ->addOption(
                self::OPT_WORKFLOW_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'Limit the report to a single workflow ID'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workflowIdOption = $input->getOption(self::OPT_WORKFLOW_ID);
        $workflowId = ($workflowIdOption !== null && $workflowIdOption !== '') ? (int) $workflowIdOption : null;

        $connection = $this->resourceConnection->getConnection();
        $executionTable = $this->resourceConnection->getTableName(self::EXECUTION_TABLE);
        $stepTable = $this->resourceConnection->getTableName(self::EXECUTION_STEP_TABLE);

        $totals = $this->fetchCountsByWorkflowAndStatus($connection, $executionTable, $workflowId, null);

        $since = (new \DateTime('-24 hours', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $last24h = $this->fetchCountsByWorkflowAndStatus($connection, $executionTable, $workflowId, $since);

        $waiting = $this->fetchWaitingStepCounts($connection, $executionTable, $stepTable, $workflowId);

        $this->renderExecutionTable($output, $totals, $last24h);
        $this->renderWaitingTable($output, $waiting);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{workflow_id:int, status:string, count:int}>
     *     keyed by "<workflow_id>|<status>"
     */
    private function fetchCountsByWorkflowAndStatus(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $executionTable,
        ?int $workflowId,
        ?string $since
    ): array {
        $select = $connection->select()
            ->from($executionTable, [
                'workflow_id' => 'workflow_id',
                'status' => 'status',
                'count' => new Expression('COUNT(*)'),
            ])
            ->group(['workflow_id', 'status'])
            ->order(['workflow_id ASC', 'status ASC']);

        if ($workflowId !== null) {
            $select->where('workflow_id = ?', $workflowId);
        }
        if ($since !== null) {
            $select->where('triggered_at >= ?', $since);
        }

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $key = $row['workflow_id'] . '|' . $row['status'];
            $rows[$key] = [
                'workflow_id' => (int) $row['workflow_id'],
                'status' => (string) $row['status'],
                'count' => (int) $row['count'],
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, int> workflow_id => waiting step count
     */
    private function fetchWaitingStepCounts(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $executionTable,
        string $stepTable,
        ?int $workflowId
    ): array {
        $select = $connection->select()
            ->from(['e' => $executionTable], ['workflow_id' => 'e.workflow_id'])
            ->joinInner(
                ['s' => $stepTable],
                's.execution_id = e.execution_id',
                ['waiting' => new Expression('COUNT(*)')]
            )
            ->where('s.status = ?', WorkflowExecutionStepInterface::STATUS_WAITING)
            ->group('e.workflow_id')
            ->order('e.workflow_id ASC');

        if ($workflowId !== null) {
            $select->where('e.workflow_id = ?', $workflowId);
        }

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int) $row['workflow_id']] = (int) $row['waiting'];
        }
        return $result;
    }

    /**
     * @param array<string, array{workflow_id:int, status:string, count:int}> $totals
     * @param array<string, array{workflow_id:int, status:string, count:int}> $last24h
     */
    private function renderExecutionTable(OutputInterface $output, array $totals, array $last24h): void
    {
        if ($totals === []) {
            $output->writeln('<comment>No executions found.</comment>');
            return;
        }

        $rows = [];
        foreach ($totals as $key => $row) {
            $rows[] = [
                $row['workflow_id'],
                $row['status'],
                $row['count'],
                $last24h[$key]['count'] ?? 0,
            ];
        }

        $table = new Table($output);
        $table->setHeaders(['Workflow ID', 'Status', 'Total', 'Last 24h'])
            ->setRows($rows)
            ->render();
    }

    /**
     * @param array<int, int> $waiting
     */
    private function renderWaitingTable(OutputInterface $output, array $waiting): void
    {
        $output->writeln('');
        $output->writeln('<info>Waiting steps (delay steps pending resume):</info>');

        if ($waiting === []) {
            $output->writeln('<comment>No steps currently waiting.</comment>');
            return;
        }

        $rows = [];
        foreach ($waiting as $workflowId => $count) {
            $rows[] = [$workflowId, $count];
        }

        $table = new Table($output);
        $table->setHeaders(['Workflow ID', 'Waiting Steps'])
            ->setRows($rows)
            ->render();
    }
}
