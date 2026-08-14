<?php
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
 *
 * Redelivery guard — NATURAL idempotence, no marker needed. Under at-least-once
 * delivery (docs/08) the executor resumes past a step row that is already
 * `complete`, but a crash INSIDE this step (track saved, step row not yet
 * marked complete) used to leave a redelivery free to append the SAME tracking
 * number a second time: a duplicate row the customer sees in "Your order has
 * shipped", plus a duplicate shipment email whenever notify is on downstream.
 * The guard is the pair itself — (carrier_code, track_number) IS the identity
 * of a parcel, so there is nothing to record: before appending, the action
 * scans the order's shipments for a track that already carries this pair and
 * returns `skipped` (with the holding shipment id) when it finds one. That
 * beats a dedupe marker on two counts: it costs no extra column/comment, and
 * it also suppresses an honest authoring duplicate (two workflows feeding the
 * same carrier number), which a per-execution marker never could.
 *
 * The scan spans ALL shipments of the order, not just the one this run would
 * write to: a redelivery that arrives after a second shipment was created
 * would otherwise target a different "latest" shipment and re-add the number
 * there. Comparison is trim + case-insensitive on both halves — carrier codes
 * are lowercase config keys and carriers treat tracking numbers
 * case-insensitively, so "1z999" and "1Z999" are the same parcel.
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

        // Redelivery guard BEFORE the write: the (carrier, number) pair is the
        // parcel's identity, so an existing match anywhere on the order means
        // this track is already attached.
        try {
            $existingShipmentId = $this->shipmentIdCarryingTrack($order, $carrierCode, $trackNumber);
        } catch (\Exception $e) {
            // An unanswered dedupe question must not become a duplicate track:
            // park for retry instead of appending blind.
            return ActionResult::failure(
                'Could not check for existing tracking: ' . $e->getMessage(),
                true
            );
        }
        if ($existingShipmentId !== null) {
            return ActionResult::skipped(
                sprintf(
                    'Tracking %s/%s is already on shipment %d of order %s',
                    $carrierCode,
                    $trackNumber,
                    $existingShipmentId,
                    $order->getIncrementId()
                ),
                [
                    'shipment_id' => $existingShipmentId,
                    'carrier_code' => $carrierCode,
                    'track_number' => $trackNumber,
                ]
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
        $items = $this->shipments($order);
        if ($items === []) {
            return null;
        }
        return end($items);
    }

    /**
     * The id of a shipment on this order that ALREADY carries this
     * (carrier_code, track_number) pair, or null when none does.
     *
     * Scans every shipment, deliberately: see the class docblock — a
     * redelivery arriving after a newer shipment appeared would otherwise
     * target a different shipment and duplicate the number there.
     *
     * A shipment object without a tracks accessor (a partial double, an
     * exotic extension) is treated as carrying nothing rather than crashing
     * the send — the worst case is the pre-existing duplicate-track behavior,
     * never a lost track.
     *
     * @param \Magento\Sales\Model\Order $order
     */
    private function shipmentIdCarryingTrack($order, string $carrierCode, string $trackNumber): ?int
    {
        foreach ($this->shipments($order) as $shipment) {
            if (!is_object($shipment) || !method_exists($shipment, 'getTracks')) {
                continue;
            }
            foreach ((array)$shipment->getTracks() as $track) {
                if (!is_object($track)
                    || !method_exists($track, 'getCarrierCode')
                    || !method_exists($track, 'getTrackNumber')
                ) {
                    continue;
                }
                if ($this->sameToken((string)$track->getCarrierCode(), $carrierCode)
                    && $this->sameToken((string)$track->getTrackNumber(), $trackNumber)
                ) {
                    return (int)$shipment->getEntityId();
                }
            }
        }
        return null;
    }

    /**
     * Carrier codes and tracking numbers compare trimmed + case-insensitively
     * (ASCII: both are machine tokens, never localized text).
     */
    private function sameToken(string $left, string $right): bool
    {
        return strcasecmp(trim($left), trim($right)) === 0;
    }

    /**
     * The order's shipments, oldest first, as a plain list.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array<int, mixed>
     */
    private function shipments($order): array
    {
        $items = $order->getShipmentsCollection()->getItems();
        return is_array($items) ? array_values($items) : [];
    }
}
