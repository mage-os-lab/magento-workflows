<?php
declare(strict_types=1);

namespace Magento\Framework\Model\ResourceModel\Db;

use Magento\Framework\Model\AbstractModel;

/**
 * Standalone-runner shim for Magento's Db\AbstractDb.
 *
 * Exists so this repo's own resource models (which extend it) can be *loaded*
 * — a unit test that needs one hands the plugin under test an anonymous
 * subclass with the DB-touching methods overridden. Everything that would
 * reach a real connection throws, per dev/tests/shims/README.md.
 */
abstract class AbstractDb
{
    /** @var string */
    protected $_mainTable = '';

    /** @var string */
    protected $_idFieldName = '';

    public function __construct()
    {
        $this->_construct();
    }

    /**
     * @return $this
     */
    protected function _init(string $mainTable, string $idFieldName)
    {
        $this->_mainTable = $mainTable;
        $this->_idFieldName = $idFieldName;
        return $this;
    }

    protected function _construct()
    {
    }

    public function getMainTable(): string
    {
        return $this->_mainTable;
    }

    public function getIdFieldName(): string
    {
        return $this->_idFieldName;
    }

    /**
     * @param string $tableName
     */
    public function getTable($tableName): string
    {
        return (string) $tableName;
    }

    /**
     * @return never
     */
    public function getConnection()
    {
        throw new \RuntimeException(
            'Shim AbstractDb has no database connection: override the calling method in a test fake.'
        );
    }

    /**
     * @param AbstractModel $object
     * @param mixed $value
     * @param string|null $field
     * @return never
     */
    public function load($object, $value, $field = null)
    {
        throw new \RuntimeException('Shim AbstractDb cannot load: override load() in a test fake.');
    }

    /**
     * Typed exactly as the real signature: this repo's resource models narrow
     * to AbstractModel, and a wider shim parameter would be an LSP violation.
     *
     * @return $this
     */
    protected function _afterLoad(AbstractModel $object)
    {
        return $this;
    }

    /**
     * @return $this
     */
    protected function _afterSave(AbstractModel $object)
    {
        return $this;
    }
}
