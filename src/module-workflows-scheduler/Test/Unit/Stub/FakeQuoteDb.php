<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * In-memory quote + mageos_workflow_abandoned_flag pair for
 * AbandonedCartDetector tests.
 *
 * The candidate query is evaluated against the WHERE fragments the detector
 * actually builds: the two updated_at bounds are read off the recorded
 * select ("q.updated_at < ?" older-than, "q.updated_at >= ?" max-age floor),
 * so the age window under test is the one the production code computed from
 * its configuration. Flag writes mutate the same in-memory flag table the
 * next query reads, so dedupe across runs behaves like the real schema.
 */
class FakeQuoteDb implements AdapterInterface
{
    /**
     * @var array<int, array{is_active: int, items_count: int, customer_email: ?string, store_id: int, updated_at: string}>
     */
    public array $quotes = [];

    /** @var array<int, array<string, mixed>> quote_id => flag row */
    public array $flags = [];

    public function addQuote(
        int $quoteId,
        string $updatedAt,
        int $isActive = 1,
        int $itemsCount = 1,
        ?string $customerEmail = 'shopper@example.com',
        int $storeId = 1
    ): void {
        $this->quotes[$quoteId] = [
            'is_active' => $isActive,
            'items_count' => $itemsCount,
            'customer_email' => $customerEmail,
            'store_id' => $storeId,
            'updated_at' => $updatedAt,
        ];
    }

    public function select()
    {
        return new FakeSelect();
    }

    public function fetchAll($select, $bind = [], $fetchMode = null)
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }

        // "q.updated_at >= ?" contains the substring "q.updated_at >", so
        // resolve the >= bound first and match < with the trailing space.
        $newerThan = $select->whereValue('updated_at >= ');
        $olderThan = $select->whereValue('updated_at < ');
        if ($olderThan === null) {
            throw new \BadMethodCallException('candidate query is expected to bind an older-than bound');
        }

        $requireActive = $select->whereValue('is_active');
        $itemsComparison = $select->whereComparison('items_count');
        $requireEmail = $select->hasWhere('customer_email IS NOT NULL');
        $excludeFlagged = $select->hasWhere('f.quote_id IS NULL');

        $rows = [];
        foreach ($this->quotes as $quoteId => $quote) {
            if ($requireActive !== null && (int) $quote['is_active'] !== (int) $requireActive) {
                continue;
            }
            if ($itemsComparison !== null) {
                [$operator, $bound] = $itemsComparison;
                $ok = match ($operator) {
                    '>' => $quote['items_count'] > (int) $bound,
                    '>=' => $quote['items_count'] >= (int) $bound,
                    default => throw new \InvalidArgumentException('unsupported operator ' . $operator),
                };
                if (!$ok) {
                    continue;
                }
            }
            if ($requireEmail && ($quote['customer_email'] === null || $quote['customer_email'] === '')) {
                continue;
            }
            // 'Y-m-d H:i:s' strings compare correctly lexicographically.
            if (strcmp($quote['updated_at'], (string) $olderThan) >= 0) {
                continue; // not old enough
            }
            if ($newerThan !== null && strcmp($quote['updated_at'], (string) $newerThan) < 0) {
                continue; // aged out (max-age window)
            }
            if ($excludeFlagged && isset($this->flags[$quoteId])) {
                continue;
            }
            $rows[] = [
                'entity_id' => $quoteId,
                'customer_email' => $quote['customer_email'],
                'store_id' => $quote['store_id'],
                'updated_at' => $quote['updated_at'],
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

    public function fetchCol($sql, $bind = [])
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
    }

    public function insertOnDuplicate($table, array $data, array $fields = [])
    {
        $this->flags[(int) $data['quote_id']] = $data;
        return 1;
    }

    public function delete($table, $where = '')
    {
        throw new \BadMethodCallException(__METHOD__ . ' not expected in these tests');
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
