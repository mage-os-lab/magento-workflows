<?php
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Shipment;

/**
 * Standalone-runner shim for the generated
 * Magento\Sales\Model\Order\Shipment\TrackFactory. Marker only: order.add_tracking
 * type-hints it, and test doubles subclass it to return a fake track. The real
 * factory is code-generated; create() throws here so an unmocked use surfaces
 * loudly.
 *
 * @method \Magento\Sales\Model\Order\Shipment\Track create(array $data = [])
 */
class TrackFactory
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
