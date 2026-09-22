<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Shipping\Model\Config as ShippingConfig;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.add_tracking — attaches a carrier tracking number to the order's
 * LATEST shipment.
 *
 * Config: carrier_code (required), track_number (required — variable
 * interpolation supplies its value, e.g. {{ trigger.tracking_number }}, since
 * config values arrive already interpolated), title (optional, defaults to the
 * carrier code).
 *
 * No-shipment handling: an order with no shipment yet cannot carry a track, and
 * that will not fix itself on redelivery — so it is a NON-retryable step
 * FAILURE with a clear message, not a skip and not a retry. Author it after a
 * create_shipment step (or gate it on the can_ship = No lifecycle condition,
 * ORD-C1) so a shipment is guaranteed present.
 */
class AddTracking extends AbstractOrderAction implements SimulateableActionInterface
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly TrackFactory $trackFactory,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShippingConfig $shippingConfig
    ) {
        parent::__construct($orderRepository);
    }

    public function getCode(): string
    {
        return 'order.add_tracking';
    }

    public function getLabel(): string
    {
        return (string)__('Add Shipment Tracking');
    }

    public function getConfigForm(): array
    {
        // Bounded option source (F6): inline the ACTIVE carriers, mirroring
        // order.change_status. The carrier list is store config, so a broken /
        // unavailable shipping config degrades to the free-text field rather
        // than rendering an empty select.
        $carrier = [
            'name' => 'carrier_code',
            'label' => 'Carrier Code',
            'type' => 'select',
            'required' => true,
        ];
        try {
            $options = [];
            foreach ($this->shippingConfig->getActiveCarriers() as $code => $model) {
                $title = trim((string)$model->getConfigData('title'));
                $options[] = [
                    'value' => (string)$code,
                    'label' => $title !== '' ? $title : (string)$code,
                ];
            }
            if ($options !== []) {
                $carrier['options'] = $options;
            }
        } catch (\Throwable $e) {
            $carrier['type'] = 'text';
        }

        return [
            $carrier,
            ['name' => 'track_number', 'label' => 'Tracking Number', 'type' => 'text', 'required' => true],
            ['name' => 'title', 'label' => 'Title (defaults to carrier)', 'type' => 'text', 'required' => false],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $carrierCode = $this->stringConfig($config, 'carrier_code');
        if ($carrierCode === null) {
            return $this->missingConfig('carrier_code');
        }
        $trackNumber = $this->stringConfig($config, 'track_number');
        if ($trackNumber === null) {
            return $this->missingConfig('track_number');
        }
        $title = $this->stringConfig($config, 'title', $carrierCode);

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        $shipment = $this->latestShipment($order);
        if ($shipment === null) {
            // No shipment to attach to, and no redelivery can create one here.
            return ActionResult::failure(
                (string)__('Order %1 has no shipment to add tracking to', $order->getIncrementId())
            );
        }

        try {
            $track = $this->trackFactory->create();
            $track->setCarrierCode($carrierCode);
            $track->setTitle($title);
            $track->setTrackNumber($trackNumber);
            $shipment->addTrack($track);
            $this->shipmentRepository->save($shipment);
        } catch (\Exception $e) {
            // Infrastructure flakiness (locks, connection drops) may succeed on retry
            return ActionResult::failure('Could not add tracking: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'shipment_id' => (int)$shipment->getEntityId(),
            'carrier_code' => $carrierCode,
            'track_number' => $trackNumber,
            'title' => $title,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $carrierCode = $this->stringConfig($config, 'carrier_code');
        if ($carrierCode === null) {
            return $this->missingConfig('carrier_code');
        }
        $trackNumber = $this->stringConfig($config, 'track_number');
        if ($trackNumber === null) {
            return $this->missingConfig('track_number');
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if ($this->latestShipment($order) === null) {
            return ActionResult::failure(
                (string)__('Order %1 has no shipment to add tracking to', $order->getIncrementId())
            );
        }
        return $this->simulated(sprintf(
            'Add %s tracking "%s" to the latest shipment of order %s',
            $carrierCode,
            $trackNumber,
            $order->getIncrementId()
        ));
    }

    /**
     * The order's most recently created shipment, or null when it has none.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return mixed shipment model, or null
     */
    private function latestShipment($order)
    {
        $items = $order->getShipmentsCollection()->getItems();
        if (!is_array($items) || $items === []) {
            return null;
        }
        return end($items);
    }
}
