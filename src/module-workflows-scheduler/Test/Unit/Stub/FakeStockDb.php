<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
     * fetchAll serves both detector queries, distinguished by main table:
     *   - candidate (crossed) query: main table is the stock-item table;
     *   - recovery (back-in-stock) query: main table is the flag table -
     *     flagged products whose qty is back above the threshold, returned with
     *     the recovered qty + sku for the inventory.back_in_stock payload.
     */
    public function fetchAll($select, $bind = [], $fetchMode = null)
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        if (str_contains($select->mainTableName(), 'stock_flag')) {
            return $this->recoveredRows($select);
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

    /**
     * Recovery (back-in-stock) rows: flagged products whose qty compares
     * against the bound threshold with the operator the detector emitted
     * ("si.qty > ?"), returned as product_id + recovered qty + sku.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recoveredRows(FakeSelect $select): array
    {
        $comparison = $select->whereComparison('qty');
        if ($comparison === null) {
            throw new \BadMethodCallException('recovery query is expected to bind a qty comparison');
        }
        [$operator, $threshold] = $comparison;

        $rows = [];
        foreach (array_keys($this->flags) as $productId) {
            if (!isset($this->stock[$productId])) {
                continue;
            }
            $item = $this->stock[$productId];
            if (!self::compare((float) $item['qty'], $operator, (float) $threshold)) {
                continue;
            }
            $rows[] = [
                'product_id' => $productId,
                'qty' => $item['qty'],
                'sku' => $item['sku'],
            ];
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

    // ---- Generated signature-faithful stubs for the remaining AdapterInterface
    // ---- surface (real reflection; none of these are exercised by the suites).

    public function beginTransaction()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function commit()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function rollBack()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function newTable($tableName = NULL, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createTable(\Magento\Framework\DB\Ddl\Table $table)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function dropTable($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createTemporaryTable(\Magento\Framework\DB\Ddl\Table $table)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createTemporaryTableLike($temporaryTableName, $originTableName, $ifNotExists = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function dropTemporaryTable($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function renameTablesBatch(array $tablePairs)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function truncateTable($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function isTableExists($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function showTableStatus($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function describeTable($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createTableByDdl($tableName, $newTableName)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function modifyColumnByDdl($tableName, $columnName, $definition, $flushData = false, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function renameTable($oldTableName, $newTableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function addColumn($tableName, $columnName, $definition, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function changeColumn($tableName, $oldColumnName, $newColumnName, $definition, $flushData = false, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function modifyColumn($tableName, $columnName, $definition, $flushData = false, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function dropColumn($tableName, $columnName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function tableColumnExists($tableName, $columnName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function addIndex($tableName, $indexName, $fields, $indexType = self::INDEX_TYPE_INDEX, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function dropIndex($tableName, $keyName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getIndexList($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function addForeignKey($fkName, $tableName, $columnName, $refTableName, $refColumnName, $onDelete = self::FK_ACTION_CASCADE, $purge = false, $schemaName = NULL, $refSchemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function dropForeignKey($tableName, $fkName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getForeignKeys($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insertMultiple($table, array $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insertArray($table, array $columns, array $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insert($table, array $bind)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insertForce($table, array $bind)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function update($table, array $bind, $where = '')
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function query($sql, $bind = [])
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function fetchAssoc($sql, $bind = [])
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function fetchPairs($sql, $bind = [])
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function fetchOne($sql, $bind = [])
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function quote($value, $type = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function quoteInto($text, $value, $type = NULL, $count = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function quoteIdentifier($ident, $auto = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function quoteColumnAs($ident, $alias, $auto = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function quoteTableAs($ident, $alias = NULL, $auto = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function formatDate($date, $includeTime = true)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function startSetup()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function endSetup()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function setCacheAdapter(\Magento\Framework\Cache\FrontendInterface $cacheAdapter)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function allowDdlCache()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function disallowDdlCache()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function resetDdlCache($tableName = NULL, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function saveDdlCache($tableCacheKey, $ddlType, $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function loadDdlCache($tableCacheKey, $ddlType)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function prepareSqlCondition($fieldName, $condition)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function prepareColumnValue(array $column, $value)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getCheckSql($condition, $true, $false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getIfNullSql($expression, $value = 0)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getConcatSql(array $data, $separator = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getLengthSql($string)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getLeastSql(array $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getGreatestSql(array $data)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDateAddSql($date, $interval, $unit)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDateSubSql($date, $interval, $unit)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDateFormatSql($date, $format)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDatePartSql($date)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getSubstringSql($stringExpression, $pos, $len = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getStandardDeviationSql($expressionField)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getDateExtractSql($date, $unit)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getTableName($tableName)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getTriggerName($tableName, $time, $event)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getIndexName($tableName, $fields, $indexType = '')
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getForeignKeyName($priTableName, $priColumnName, $refTableName, $refColumnName)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function disableTableKeys($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function enableTableKeys($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function insertFromSelect(\Magento\Framework\DB\Select $select, $table, array $fields = [], $mode = false)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function selectsByRange($rangeField, \Magento\Framework\DB\Select $select, $stepCount = 100)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function updateFromSelect(\Magento\Framework\DB\Select $select, $table)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function deleteFromSelect(\Magento\Framework\DB\Select $select, $table)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getTablesChecksum($tableNames, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function supportStraightJoin()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function orderRand(\Magento\Framework\DB\Select $select, $field = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function forUpdate($sql)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getPrimaryKeyName($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function decodeVarbinary($value)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getTransactionLevel()
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function createTrigger(\Magento\Framework\DB\Ddl\Trigger $trigger)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function dropTrigger($triggerName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getTables($likeCondition = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getCaseSql($valueName, $casesResults, $defaultValue = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }

    public function getAutoIncrementField($tableName, $schemaName = NULL)
    {
        throw new \BadMethodCallException(__METHOD__);
    }
}
