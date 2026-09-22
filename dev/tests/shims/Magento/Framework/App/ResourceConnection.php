<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App;

/**
 * Standalone-runner shim for Magento\Framework\App\ResourceConnection.
 *
 * Plain class with the method signatures unit tests type against. There is
 * no database in the standalone runner, so every method throws unless a test
 * subclasses this shim (or wraps it) and overrides the behaviour it needs.
 */
class ResourceConnection
{
    public const DEFAULT_CONNECTION = 'default';

    public function getConnection(string $resourceName = self::DEFAULT_CONNECTION)
    {
        throw new \RuntimeException(
            'ResourceConnection::getConnection() is not available in the standalone runner; override it in a test double.'
        );
    }

    public function getConnectionByName(string $connectionName)
    {
        throw new \RuntimeException(
            'ResourceConnection::getConnectionByName() is not available in the standalone runner; override it in a test double.'
        );
    }

    public function getTableName($modelEntity, string $connectionName = self::DEFAULT_CONNECTION)
    {
        throw new \RuntimeException(
            'ResourceConnection::getTableName() is not available in the standalone runner; override it in a test double.'
        );
    }
}
