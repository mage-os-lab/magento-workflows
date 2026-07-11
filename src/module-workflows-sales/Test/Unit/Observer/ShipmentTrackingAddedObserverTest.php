<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Shipment\Track;
use MageOS\WorkflowsSales\Observer\ShipmentTrackingAddedObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the tracking-added contract (DOC-T2): 'sales.shipment.tracking_added' is
 * published hydrating the parent order (entity=sales_order, mirroring
 * sales.invoice.paid) with carrier_code, title, track_number and the shipment
 * increment_id as payload extras. No order id -> no publish; a publish failure
 * is logged and never breaks the save.
 */
class ShipmentTrackingAddedObserverTest extends TestCase
{
    private function track(
        int $orderId,
        string $carrier = 'ups',
        string $title = 'UPS',
        string $number = '1Z999',
        ?string $shipmentIncrement = '900000001'
    ): Track {
        $shipment = $shipmentIncrement === null ? null : new class($shipmentIncrement) {
            public function __construct(private readonly string $increment)
            {
            }
            public function getIncrementId(): string
            {
                return $this->increment;
            }
        };

        return new class($orderId, $carrier, $title, $number, $shipment) extends Track {
            public function __construct(
                private readonly int $orderId,
                private readonly string $carrier,
                private readonly string $title,
                private readonly string $number,
                private readonly mixed $shipment
            ) {
            }
            public function getOrderId()
            {
                return $this->orderId;
            }
            public function getCarrierCode()
            {
                return $this->carrier;
            }
            public function getTitle()
            {
                return $this->title;
            }
            public function getTrackNumber()
            {
                return $this->number;
            }
            public function getShipment()
            {
                return $this->shipment;
            }
        };
    }

    private function event(mixed $track): Observer
    {
        return new Observer(['event' => new Event($track === null ? [] : ['data_object' => $track])]);
    }

    public function testPublishesTrackingAddedHydratingTheParentOrder(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ShipmentTrackingAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->track(
            orderId: 88,
            carrier: 'fedex',
            title: 'FedEx',
            number: '7700123',
            shipmentIncrement: '900000042'
        )));

        $this->assertCount(1, $publisher->published);
        $data = $publisher->published[0]['data'];
        $this->assertSame('sales.shipment.tracking_added', $publisher->published[0]['event']);
        $this->assertSame(88, $data['id']);
        $this->assertSame(88, $data['entity_id']);
        $this->assertSame(88, $data['order_id']);
        $this->assertSame('fedex', $data['carrier_code']);
        $this->assertSame('FedEx', $data['title']);
        $this->assertSame('7700123', $data['track_number']);
        $this->assertSame('900000042', $data['shipment_increment_id']);
    }

    public function testShipmentIncrementIsNullWhenTrackHasNoShipment(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ShipmentTrackingAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->track(orderId: 88, shipmentIncrement: null)));

        $this->assertCount(1, $publisher->published);
        $this->assertNull($publisher->published[0]['data']['shipment_increment_id']);
    }

    public function testDoesNotFireWithoutOrderId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ShipmentTrackingAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->track(orderId: 0)));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutTrackInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ShipmentTrackingAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event(null));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new ShipmentTrackingAddedObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->event($this->track(orderId: 88)));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('sales.shipment.tracking_added', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
