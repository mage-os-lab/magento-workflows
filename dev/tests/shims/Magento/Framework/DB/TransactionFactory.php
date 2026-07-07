<?php
declare(strict_types=1);

namespace Magento\Framework\DB;

/**
 * Standalone-runner shim for Magento\Framework\DB\TransactionFactory.
 * Creating a real DB transaction needs the object manager, so it throws
 * unless a test subclass overrides it.
 */
class TransactionFactory
{
    /**
     * @param array $data
     * @return \Magento\Framework\DB\Transaction
     */
    public function create(array $data = [])
    {
        throw new \RuntimeException('create() not implemented in shim');
    }
}
