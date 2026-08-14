<?php
declare(strict_types=1);

namespace Magento\Framework\DB\Adapter;

/**
 * Standalone-runner shim for Magento\Framework\DB\Adapter\AdapterInterface —
 * only the surface the workflow modules touch (select building, fetches, and
 * the flag-table writes used by the scheduler detectors). The real interface
 * is far larger; per dev/tests/shims/README.md, shims implement only the
 * minimum surface the tests exercise, with signatures matching the real
 * methods so test doubles stay drop-in compatible.
 */
interface AdapterInterface
{
    /**
     * @return mixed a select object exposing from()/join()/joinLeft()/where()/limit()
     */
    public function select();

    /**
     * @param mixed $sql
     * @param mixed $bind
     * @param mixed $fetchMode
     * @return array
     */
    public function fetchAll($sql, $bind = [], $fetchMode = null);

    /**
     * @param mixed $sql
     * @param mixed $bind
     * @param mixed $fetchMode
     * @return mixed row array or false
     */
    public function fetchRow($sql, $bind = [], $fetchMode = null);

    /**
     * @param mixed $sql
     * @param mixed $bind
     * @return array
     */
    public function fetchCol($sql, $bind = []);

    /**
     * @param mixed $table
     * @param array $data
     * @param array $fields
     * @return int affected rows
     */
    public function insertOnDuplicate($table, array $data, array $fields = []);

    /**
     * Plain INSERT — the duplicate-key error it raises IS the answer for the
     * atomic claim idioms (Dispatcher debounce, SendClaimStore).
     *
     * @param mixed $table
     * @param array $bind
     * @return int affected rows
     */
    public function insert($table, array $bind);

    /**
     * @param mixed $table
     * @param array $bind
     * @param mixed $where
     * @return int affected rows
     */
    public function update($table, array $bind, $where = '');

    /**
     * @param mixed $table
     * @param mixed $where
     * @return int affected rows
     */
    public function delete($table, $where = '');
}
