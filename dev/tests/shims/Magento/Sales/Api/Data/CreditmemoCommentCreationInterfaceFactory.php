<?php
declare(strict_types=1);

namespace Magento\Sales\Api\Data;

/**
 * Standalone-runner shim for the generated
 * Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory. Marker
 * only: order.create_creditmemo type-hints it to build the dedupe comment that
 * rides into the memo, and test doubles subclass it to return a recording
 * comment object. The real factory is code-generated; create() throws here so
 * an unmocked use surfaces.
 *
 * @method \Magento\Sales\Api\Data\CreditmemoCommentCreationInterface create(array $data = [])
 */
class CreditmemoCommentCreationInterfaceFactory
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
