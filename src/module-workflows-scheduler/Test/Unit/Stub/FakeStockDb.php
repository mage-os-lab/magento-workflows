<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * In-memory cataloginventory_stock_item + mageos_workflow_stock_flag pair
 * for StockThresholdDetector tests.
 *
 * The candidate and recovery queries are evaluated against the WHERE
 * fragments the detector actually builds: the qty comparison operator and
 * bound threshold are parsed out of the recorded select ("si.qty <= ?" vs
 * "si.qty > ?"), so the at-the-boundary semantics the docs promise (fire at
 * or below, re-arm only strictly above) are exercised against production
 * code, not hardcoded in this fake. Flag inserts/deletes mutate the same
 * in-memory flag table the next query reads — multi-run hysteresis behaves
 * like the real schema.
 */
class FakeStockDb implements AdapterInterface
{
    /**
     * @var array<int, array{qty: float, is_in_stock: int, use_config_manage_stock: int, manage_stock: int, sku: string}>
     */
    public array $stock = [];

    /** @var array<int, array<string, mixed>> product_id => flag row */
    public array $flags = [];

    public function setQty(int $productId, float $qty): void
    {
        $this->stock[$productId]['qty'] = $qty;
    }

    public function addProduct(
        int $productId,
        float $qty,
        string $sku,
        int $isInStock = 1,
        int $useConfigManageStock = 1,
        int $manageStock = 0
    ): void {
        $this->stock[$productId] = [
            'qty' => $qty,
            'is_in_stock' => $isInStock,
            'use_config_manage_stock' => $useConfigManageStock,
            'manage_stock' => $manageStock,
            'sku' => $sku,
        ];
    }

    public function select()
    {
        return new FakeSelect();
    }

    /**
     * Candidate query: main table is the stock-item table.
     */
    public function fetchAll($select, $bind = [], $fetchMode = null)
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        $comparison = $select->whereComparison('qty');
        if ($comparison === null) {
            throw new \BadMethodCallException('candidate query is expected to bind a qty comparison');
        }
        [$operator, $threshold] = $comparison;
        $inStock = $select->whereValue('is_in_stock');
        $requireManaged = $select->hasWhere('manage_stock');
        $excludeFlagged = $select->hasWhere('f.product_id IS NULL');

        $rows = [];
        foreach ($this->stock as $productId => $item) {
            if (!self::compare((float) $item['qty'], $operator, (float) $threshold)) {
                continue;
            }
            if ($inStock !== null && (int) $item['is_in_stock'] !== (int) $inStock) {
                continue;
            }
            if ($requireManaged
                && (int) $item['use_config_manage_stock'] !== 1
                && (int) $item['manage_stock'] !== 1
            ) {
                continue;
            }
            if ($excludeFlagged && isset($this->flags[$productId])) {
                continue;
            }
            $rows[] = [
                'product_id' => $productId,
                'qty' => $item['qty'],
                'sku' => $item['sku'],
            ];
            if ($select->limitCount !== null && count($rows) >= $select->limitCount) {
                break;
            }
        }
        return $rows;
    }

    public function fetchRow($sql, $bind = [], $fetchMode = null)
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }

    /**
     * Recovery query: flagged products whose qty compares against the bound
     * threshold with the operator the detector emitted.
     */
    public function fetchCol($select, $bind = [])
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        $comparison = $select->whereComparison('qty');
        if ($comparison === null) {
            throw new \BadMethodCallException('recovery query is expected to bind a qty comparison');
        }
        [$operator, $threshold] = $comparison;

        $ids = [];
        foreach (array_keys($this->flags) as $productId) {
            if (!isset($this->stock[$productId])) {
                continue;
            }
            if (self::compare((float) $this->stock[$productId]['qty'], $operator, (float) $threshold)) {
                $ids[] = $productId;
            }
        }
        return $ids;
    }

    public function insertOnDuplicate($table, array $data, array $fields = [])
    {
        $this->flags[(int) $data['product_id']] = $data;
        return 1;
    }

    public function delete($table, $where = '')
    {
        $deleted = 0;
        foreach ((array) $where as $ids) {
            foreach ((array) $ids as $id) {
                if (isset($this->flags[(int) $id])) {
                    unset($this->flags[(int) $id]);
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    private static function compare(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            '=' => $left == $right,
            default => throw new \InvalidArgumentException('unsupported operator ' . $operator),
        };
    }
}
