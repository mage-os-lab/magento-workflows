<?php
declare(strict_types=1);

namespace Magento\Framework;

/**
 * Standalone-runner shim for Magento\Framework\DataObjectFactory: mirrors the
 * generated factory's create(['data' => [...]]) contract, returning a
 * DataObject seeded with the given data.
 */
class DataObjectFactory
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function create(array $arguments = []): DataObject
    {
        $data = $arguments['data'] ?? [];

        return new DataObject(is_array($data) ? $data : []);
    }
}
