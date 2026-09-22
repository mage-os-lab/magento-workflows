<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Model\Order\Shipment;

/**
 * Standalone-runner shim for Magento\Sales\Model\Order\Shipment\Track (an
 * AbstractModel in core, whose save dispatches sales_order_shipment_track_save_after).
 * ShipmentTrackingAddedObserver (DOC-T2) reads these getters off the event's
 * data_object; test doubles subclass and override them.
 */
class Track
{
    public function getEntityId()
    {
        return null;
    }

    public function getOrderId()
    {
        return null;
    }

    public function getCarrierCode()
    {
        return null;
    }

    public function getTitle()
    {
        return null;
    }

    public function getTrackNumber()
    {
        return null;
    }

    /**
     * @return mixed shipment model or null
     */
    public function getShipment()
    {
        return null;
    }
}
