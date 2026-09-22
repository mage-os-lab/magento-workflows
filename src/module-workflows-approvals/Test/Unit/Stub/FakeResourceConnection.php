<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

use Magento\Framework\App\ResourceConnection;

/**
 * ResourceConnection double returning a single shared InMemoryConnection and
 * echoing table names verbatim (the shim has no schema prefix).
 */
class FakeResourceConnection extends ResourceConnection
{
    public function __construct(private readonly InMemoryConnection $connection)
    {
    }

    public function getConnection($resourceName = self::DEFAULT_CONNECTION)
    {
        return $this->connection;
    }

    public function getTableName($modelEntity, $connectionName = self::DEFAULT_CONNECTION)
    {
        return (string) $modelEntity;
    }

    public function connection(): InMemoryConnection
    {
        return $this->connection;
    }
}
