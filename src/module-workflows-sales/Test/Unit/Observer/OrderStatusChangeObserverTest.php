<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\WorkflowsSales\Observer\OrderStatusChangeObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeOrder;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the gap-fill contract: 'sales.order.status_changed' is published with
 * the from/to statuses ONLY on a real transition. New orders (original
 * status null) are excluded — 'sales.order.created' covers them — as are
 * saves that keep the status unchanged. A publish failure is logged and
 * never breaks the order save.
 */
class OrderStatusChangeObserverTest extends TestCase
{
    private function observerEvent(?FakeOrder $order): Observer
    {
        $data = $order === null ? [] : ['order' => $order];
        return new Observer(['event' => new Event($data)]);
    }

    public function testPublishesFromAndToStatusOnRealChange(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderStatusChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(new FakeOrder(11, 'processing', 'pending', '100000011')));

        $this->assertCount(1, $publisher->published);
        $this->assertSame('sales.order.status_changed', $publisher->published[0]['event']);
        $this->assertSame(11, $publisher->published[0]['data']['id']);
        $this->assertSame(11, $publisher->published[0]['data']['entity_id']);
        $this->assertSame('pending', $publisher->published[0]['data']['from_status']);
        $this->assertSame('processing', $publisher->published[0]['data']['to_status']);
    }

    public function testDoesNotFireWhenStatusUnchanged(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderStatusChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(new FakeOrder(11, 'processing', 'processing')));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireForNewOrderWithNullOriginalStatus(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderStatusChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(new FakeOrder(11, 'pending', null)));

        $this->assertCount(0, $publisher->published, 'order creation is covered by sales.order.created');
    }

    public function testDoesNotFireWithoutPersistedEntityId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderStatusChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(new FakeOrder(0, 'processing', 'pending')));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutOrderInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderStatusChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(null));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakOrderSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new OrderStatusChangeObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->observerEvent(new FakeOrder(11, 'processing', 'pending', '100000011')));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('sales.order.status_changed', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
        $this->assertStringContainsString('100000011', $logger->allMessages());
    }
}
