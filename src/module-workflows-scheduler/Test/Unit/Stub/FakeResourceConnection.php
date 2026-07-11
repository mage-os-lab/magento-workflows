<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use Magento\Framework\App\ResourceConnection;

/**
 * ResourceConnection double: hands out the injected fake connection object
 * and treats logical table names as physical (identity getTableName()).
 * When constructed without a connection, any getConnection() call throws —
 * useful for proving a code path never touches the database at all.
 */
class FakeResourceConnection extends ResourceConnection
{
    public int $getConnectionCalls = 0;

    public function __construct(private readonly ?object $connection = null)
    {
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        $this->getConnectionCalls++;
        if ($this->connection === null) {
            throw new \BadMethodCallException(
                'getConnection() was not expected to be called in this test'
            );
        }
        return $this->connection;
    }

    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return (string) $modelEntity;
    }
}
