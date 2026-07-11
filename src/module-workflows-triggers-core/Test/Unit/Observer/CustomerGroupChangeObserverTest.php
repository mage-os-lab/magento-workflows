<?php
declare(strict_types=1);

namespace MageOS\WorkflowsTriggersCore\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\WorkflowsTriggersCore\Observer\CustomerGroupChangeObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\FakeCustomer;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the gap-fill contract: 'customer.group_changed' fires with from/to
 * group ids only on a real transition. Brand-new customers (no original
 * group) are excluded — 'customer.created' covers them — and unchanged
 * groups do not fire. Publish failures are logged, never rethrown into the
 * customer save.
 */
class CustomerGroupChangeObserverTest extends TestCase
{
    /**
     * @param array $eventData
     */
    private function observerEvent(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    public function testPublishesFromAndToGroupOnRealChange(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new CustomerGroupChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['customer' => new FakeCustomer(8, 2, 1)]));

        $this->assertCount(1, $publisher->published);
        $this->assertSame('customer.group_changed', $publisher->published[0]['event']);
        $this->assertSame(8, $publisher->published[0]['data']['customerId']);
        $this->assertSame(8, $publisher->published[0]['data']['entity_id']);
        $this->assertSame(1, $publisher->published[0]['data']['from_group_id']);
        $this->assertSame(2, $publisher->published[0]['data']['to_group_id']);
    }

    public function testDoesNotFireForBrandNewCustomer(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new CustomerGroupChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['customer' => new FakeCustomer(8, 1, null)]));

        $this->assertCount(0, $publisher->published, 'customer creation is covered by customer.created');
    }

    public function testDoesNotFireWhenGroupUnchanged(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new CustomerGroupChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['customer' => new FakeCustomer(8, 2, 2)]));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWhenGroupUnchangedAcrossTypeJuggling(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new CustomerGroupChangeObserver($publisher, new RecordingLogger());

        // orig data often carries strings from the DB: '2' -> 2 is not a change
        $observer->execute($this->observerEvent(['customer' => new FakeCustomer(8, 2, '2')]));

        $this->assertCount(0, $publisher->published);
    }

    public function testReadsCustomerFromObjectKeyFallback(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new CustomerGroupChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['object' => new FakeCustomer(8, 3, 1)]));

        $this->assertCount(1, $publisher->published);
    }

    public function testDoesNotFireWithoutCustomerInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new CustomerGroupChangeObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([]));
        $observer->execute($this->observerEvent(['customer' => new \stdClass()]));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakCustomerSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new CustomerGroupChangeObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->observerEvent(['customer' => new FakeCustomer(8, 2, 1)]));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('customer.group_changed', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
