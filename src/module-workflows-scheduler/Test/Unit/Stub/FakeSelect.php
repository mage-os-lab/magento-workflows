<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

/**
 * Recording stand-in for the select object a DB adapter builds: chainable
 * from()/join()/joinLeft()/where()/limit() that captures the table, join and
 * where fragments the production code emits. The fake adapters interpret the
 * RECORDED fragments (bound values and comparison operators included), so
 * boundary semantics like "<= threshold" vs "< threshold" come from the code
 * under test, not from the fake.
 */
class FakeSelect
{
    /** @var array<string, string>|string main table: alias => name, or plain name */
    public array|string $mainTable = '';

    /** @var string[] main-table columns as recorded */
    public array $columns = [];

    /** @var array<int, array{0:mixed, 1:string}> [table spec, join condition] */
    public array $joins = [];

    /** @var array<int, array{0:string, 1:mixed}> [where fragment, bound value] */
    public array $wheres = [];

    public ?int $limitCount = null;

    public function from($name, $cols = '*', $schema = null): self
    {
        $this->mainTable = $name;
        $this->columns = (array) $cols;
        return $this;
    }

    public function join($name, $cond, $cols = '*', $schema = null): self
    {
        $this->joins[] = [$name, (string) $cond];
        return $this;
    }

    public function joinLeft($name, $cond, $cols = '*', $schema = null): self
    {
        $this->joins[] = [$name, (string) $cond];
        return $this;
    }

    public function where($cond, $value = null, $type = null): self
    {
        $this->wheres[] = [(string) $cond, $value];
        return $this;
    }

    public function limit($count, $offset = 0): self
    {
        $this->limitCount = (int) $count;
        return $this;
    }

    public function mainTableName(): string
    {
        return is_array($this->mainTable) ? (string) reset($this->mainTable) : (string) $this->mainTable;
    }

    public function hasWhere(string $needle): bool
    {
        foreach ($this->wheres as [$cond]) {
            if (str_contains($cond, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bound value of the first where fragment containing $needle, or null.
     */
    public function whereValue(string $needle)
    {
        foreach ($this->wheres as [$cond, $value]) {
            if (str_contains($cond, $needle)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Parses "col <op> ?" out of the first where fragment naming $column.
     *
     * @return array{0:string, 1:mixed}|null [operator, bound value]
     */
    public function whereComparison(string $column): ?array
    {
        foreach ($this->wheres as [$cond, $value]) {
            if (str_contains($cond, $column)
                && preg_match('/(<=|>=|<>|!=|<|>|=)\s*\?/', $cond, $m)
            ) {
                return [$m[1], $value];
            }
        }
        return null;
    }
}
