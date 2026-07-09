<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\ShipmentRepositoryInterface.
 * Minimal marker (mirrors the OrderRepositoryInterface shim style) so
 * order.add_tracking's constructor type-hint and test doubles resolve without a
 * full Magento install. Only save() is exercised by the action; a test double
 * may add the remaining CRUD methods.
 */
interface ShipmentRepositoryInterface
{
    /**
     * @param mixed $entity
     * @return mixed
     */
    public function save($entity);
}
