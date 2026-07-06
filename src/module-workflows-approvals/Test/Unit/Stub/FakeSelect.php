<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

/**
 * Minimal Zend/Magento-DB-select stand-in: records the from-table, selected
 * columns, and where predicates the InMemoryConnection evaluates.
 */
class FakeSelect
{
    public string $table = '';

    /** @var string[]|null selected columns, or null for all */
    public ?array $columns = null;

    /** @var array<int, array{0: string, 1: mixed}> */
    public array $wheres = [];

    public function from($table, $columns = '*'): self
    {
        $this->table = (string) $table;
        $this->columns = is_array($columns) ? array_values($columns) : null;
        return $this;
    }

    public function where($condition, $value = null): self
    {
        $this->wheres[] = [(string) $condition, $value];
        return $this;
    }

    public function order($spec): self
    {
        return $this;
    }

    public function limit($count, $offset = 0): self
    {
        return $this;
    }
}
