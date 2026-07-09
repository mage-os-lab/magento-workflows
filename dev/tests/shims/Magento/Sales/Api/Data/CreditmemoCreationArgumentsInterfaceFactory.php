<?php
declare(strict_types=1);

namespace Magento\Sales\Api\Data;

/**
 * Standalone-runner shim for the generated
 * Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory. Marker
 * only: order.create_creditmemo type-hints it for the partial-refund modes and
 * test doubles subclass it to return a recording arguments object. The real
 * factory is code-generated; create() throws here so an unmocked use surfaces.
 *
 * @method \Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface create(array $data = [])
 */
class CreditmemoCreationArgumentsInterfaceFactory
{
    /**
     * @param array $data
     * @return mixed
     */
    public function create(array $data = [])
    {
        throw new \RuntimeException('create() not implemented in shim');
    }
}
