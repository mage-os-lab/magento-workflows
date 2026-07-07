<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

/**
 * In-memory mageos_workflow_schedule_state table for RunScheduledWorkflows
 * tests: fetchRow() resolves the workflow_id bound on the select,
 * insertOnDuplicate() upserts the row — so state written by one cron tick is
 * visible to the next, exactly like the real table.
 */
class ScheduleStateConnection
{
    /**
     * @var array<int, array{last_run_at: ?string, last_run_watermark: ?string}>
     */
    public array $rows = [];

    public function select(): FakeSelect
    {
        return new FakeSelect();
    }

    /**
     * @param FakeSelect $select
     * @return array|false
     */
    public function fetchRow($select)
    {
        $workflowId = (int) $select->whereValue('workflow_id');
        return $this->rows[$workflowId] ?? false;
    }

    public function insertOnDuplicate($table, array $data, array $fields = []): int
    {
        $this->rows[(int) $data['workflow_id']] = [
            'last_run_at' => $data['last_run_at'] ?? null,
            'last_run_watermark' => $data['last_run_watermark'] ?? null,
        ];
        return 1;
    }
}
