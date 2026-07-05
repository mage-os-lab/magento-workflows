<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

use Magento\Framework\Exception\AlreadyExistsException;

/**
 * A tiny in-memory stand-in for the DB adapter covering exactly the surface the
 * task manager, decision service, and reconciliation sweep use: insert with a
 * (execution_id, step_key) unique key, conditional update returning an
 * affected-row count, and select/fetch* over the approval and execution tables.
 * Row counts are the arbiter every claim branches on, so they are modelled
 * faithfully (a status-guarded update on an already-changed row returns 0).
 */
class InMemoryConnection
{
    private const APPROVAL_TABLE = 'mageos_workflow_approval';
    private const EXECUTION_TABLE = 'mageos_workflow_execution';
    private const STEP_TABLE = 'mageos_workflow_execution_step';

    /** @var array<int, array<string, mixed>> approval_id => row */
    public array $approvals = [];

    /** @var array<int, array<string, mixed>> execution_id => row */
    public array $executions = [];

    /** @var array<int, string|null> execution_id => parked step result */
    public array $stepResults = [];

    /**
     * Forced result for the waiting -> pending execution claim: null (default)
     * evaluates the claim's WHERE predicates against the seeded row (so
     * status/current_step scoping is really exercised); 0 simulates the sweeper
     * winning the race regardless of the row.
     */
    public ?int $executionClaimResult = null;

    /** @var array<int, array{table: string, bind: array, where: array}> */
    public array $updates = [];

    public ?CallLog $log = null;

    private int $approvalAutoId = 0;

    public function seedApproval(array $row): int
    {
        $id = ++$this->approvalAutoId;
        $row['approval_id'] = $id;
        $this->approvals[$id] = $row;
        return $id;
    }

    public function seedExecution(array $row): void
    {
        $this->executions[(int) $row['execution_id']] = $row;
    }

    public function select(): FakeSelect
    {
        return new FakeSelect();
    }

    public function insert(string $table, array $bind): void
    {
        if ($table === self::APPROVAL_TABLE) {
            foreach ($this->approvals as $row) {
                if ((int) $row['execution_id'] === (int) $bind['execution_id']
                    && (string) $row['step_key'] === (string) $bind['step_key']
                ) {
                    throw new AlreadyExistsException();
                }
            }
            $this->seedApproval($bind);
        }
    }

    public function update(string $table, array $bind, $where = ''): int
    {
        $where = is_array($where) ? $where : [];
        $this->updates[] = ['table' => $table, 'bind' => $bind, 'where' => $where];

        if ($table === self::APPROVAL_TABLE) {
            $count = 0;
            foreach ($this->approvals as $id => $row) {
                if ($this->matches($row, $this->whereList($where))) {
                    $this->approvals[$id] = array_merge($row, $bind);
                    $count++;
                }
            }
            return $count;
        }

        if ($table === self::EXECUTION_TABLE) {
            $newStatus = $bind['status'] ?? null;
            $executionId = $this->intValue($where, 'execution_id');
            if ($newStatus === 'pending') {
                if ($this->executionClaimResult !== null) {
                    if ($this->executionClaimResult >= 1 && $executionId !== null && isset($this->executions[$executionId])) {
                        $this->executions[$executionId]['status'] = 'pending';
                    }
                    return $this->executionClaimResult;
                }
                $row = $executionId !== null ? ($this->executions[$executionId] ?? null) : null;
                if ($row !== null && $this->matches($row, $this->whereList($where))) {
                    $this->executions[$executionId]['status'] = 'pending';
                    return 1;
                }
                return 0;
            }
            if ($newStatus === 'waiting') {
                if ($executionId !== null && isset($this->executions[$executionId])) {
                    $this->executions[$executionId]['status'] = 'waiting';
                }
                return 1;
            }
            return 1;
        }

        if ($table === self::STEP_TABLE) {
            $executionId = $this->intValue($where, 'execution_id');
            $result = $bind['result'] ?? null;
            if ($executionId !== null) {
                $this->stepResults[$executionId] = $result === null ? null : (string) $result;
            }
            if ($this->log !== null) {
                $this->log->add($result === null ? 'result:clear' : 'result:write');
            }
            return 1;
        }

        return 0;
    }

    public function fetchRow($select)
    {
        $rows = $this->matchedRows($select);
        return $rows === [] ? false : $rows[0];
    }

    public function fetchOne($select)
    {
        $rows = $this->matchedRows($select);
        if ($rows === []) {
            return false;
        }
        $column = $select->columns[0] ?? array_key_first($rows[0]);
        return $rows[0][$column] ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select): array
    {
        return $this->matchedRows($select);
    }

    /**
     * @return array<int, mixed>
     */
    public function fetchCol($select): array
    {
        $rows = $this->matchedRows($select);
        $column = $select->columns[0] ?? null;
        return array_map(
            static fn (array $r) => $column !== null ? ($r[$column] ?? null) : reset($r),
            $rows
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function matchedRows(FakeSelect $select): array
    {
        $source = match ($select->table) {
            self::APPROVAL_TABLE => array_values($this->approvals),
            self::EXECUTION_TABLE => array_values($this->executions),
            default => [],
        };
        return array_values(array_filter(
            $source,
            fn (array $row) => $this->matches($row, $select->wheres)
        ));
    }

    /**
     * @param array<int, array{0: string, 1: mixed}> $wheres
     */
    private function matches(array $row, array $wheres): bool
    {
        foreach ($wheres as [$condition, $value]) {
            $column = strtok($condition, ' ');
            $actual = $row[$column] ?? null;
            if (str_contains($condition, ' IN ')) {
                $set = array_map('strval', is_array($value) ? $value : [$value]);
                if (!in_array((string) $actual, $set, true)) {
                    return false;
                }
            } elseif (str_contains($condition, '!=')) {
                if ((string) $actual === (string) $value) {
                    return false;
                }
            } else {
                if ((string) $actual !== (string) $value) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<int, array{0: string, 1: mixed}> $where update-style where array
     * @return array<int, array{0: string, 1: mixed}>
     */
    private function whereList(array $where): array
    {
        $list = [];
        foreach ($where as $condition => $value) {
            $list[] = [(string) $condition, $value];
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $where update-style where array
     */
    private function intValue(array $where, string $column): ?int
    {
        foreach ($where as $condition => $value) {
            if (str_starts_with((string) $condition, $column . ' ')) {
                return (int) $value;
            }
        }
        return null;
    }
}
