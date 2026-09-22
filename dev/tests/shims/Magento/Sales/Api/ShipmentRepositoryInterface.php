<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\ShipmentRepositoryInterface.
 * Mirrors the FULL real interface (5 methods) so a partial double is caught
 * here, not only under real Magento. Only save() is exercised by
 * order.add_tracking.
 */
interface ShipmentRepositoryInterface
{
    public function create();

    public function get($id);

    public function getList($searchCriteria);

    public function delete($entity);

    public function save($entity);
}
