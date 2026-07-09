<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects a carrier tracking number being attached to a
 * shipment and publishes the 'sales.shipment.tracking_added' async event
 * (declared in etc/async_events.xml), hydrating the PARENT order
 * (entity=sales_order) exactly like sales.invoice.paid (DOC-T1) — the shipment
 * document has no condition root of its own, so its fields ride the payload and
 * the order behind it is the hydration target.
 *
 * Seam: Magento\Sales\Model\Order\Shipment\Track is an AbstractModel; saving it
 * dispatches 'sales_order_shipment_track_save_after' with the track model on the
 * event's always-present 'data_object' key. This is the moment a tracking number
 * becomes real — the anchor for "email the customer their tracking link".
 *
 * Loop-guard interaction (documented — this is a REAL loop pair): the
 * order.add_tracking ACTION (ORD-A1) saves a Track, which dispatches this very
 * event, which can run a workflow that adds another track. The engine's
 * chain-depth guard bounds the trigger→action→trigger recursion; author
 * tracking-driven workflows within that depth budget and prefer gating them
 * (e.g. only when no track email has been sent) rather than re-adding tracks.
 * Publishing failures are logged and never break the track/shipment save.
 */
class ShipmentTrackingAddedObserver implements ObserverInterface
{
    public const EVENT_NAME = 'sales.shipment.tracking_added';

    public function __construct(
        private readonly EventPublisher $eventPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $track = $observer->getEvent()->getData('data_object');
        if (!$track instanceof Track) {
            return;
        }

        $orderId = (int)$track->getOrderId();
        if ($orderId <= 0) {
            return;
        }

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'id' hydrates the parent order via OrderRepositoryInterface::get($id)
                'id' => $orderId,
                'entity_id' => $orderId,
                'order_id' => $orderId,
                'carrier_code' => (string)$track->getCarrierCode(),
                'title' => (string)$track->getTitle(),
                'track_number' => (string)$track->getTrackNumber(),
                'shipment_increment_id' => $this->shipmentIncrementId($track),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for order %d: %s',
                    self::EVENT_NAME,
                    $orderId,
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }

    /**
     * The parent shipment's increment_id, or null when the shipment is not
     * resolvable from the track (never let a missing back-reference break the
     * publish).
     */
    private function shipmentIncrementId(Track $track): ?string
    {
        $shipment = $track->getShipment();
        if ($shipment === null || !method_exists($shipment, 'getIncrementId')) {
            return null;
        }
        $increment = $shipment->getIncrementId();
        return $increment !== null ? (string)$increment : null;
    }
}
