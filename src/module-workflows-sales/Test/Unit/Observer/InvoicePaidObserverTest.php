<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Invoice;
use MageOS\WorkflowsSales\Observer\InvoicePaidObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the invoice-paid contract (DOC-T1): 'sales.invoice.paid' is published
 * hydrating the parent ORDER (entity=sales_order, id = order id — mirroring
 * sales.invoice.created) with the invoice increment_id, grand_total and
 * order_id as payload extras. No order id -> no publish; a publish failure is
 * logged and never breaks the save.
 */
class InvoicePaidObserverTest extends TestCase
{
    private function invoice(int $orderId, string $increment = 'INV-1', float $grandTotal = 99.5): Invoice
    {
        return new class($orderId, $increment, $grandTotal) extends Invoice {
            public function __construct(
                private readonly int $orderId,
                private readonly string $increment,
                private readonly float $grandTotal
            ) {
            }

            public function getOrderId()
            {
                return $this->orderId;
            }

            public function getIncrementId()
            {
                return $this->increment;
            }

            public function getGrandTotal()
            {
                return $this->grandTotal;
            }
        };
    }

    private function event(mixed $invoice): Observer
    {
        return new Observer(['event' => new Event($invoice === null ? [] : ['invoice' => $invoice])]);
    }

    public function testPublishesInvoicePaidHydratingTheParentOrder(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new InvoicePaidObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->invoice(77, 'INV-100', 250.0)));

        $this->assertCount(1, $publisher->published);
        $this->assertSame('sales.invoice.paid', $publisher->published[0]['event']);
        $this->assertSame(77, $publisher->published[0]['data']['id']);
        $this->assertSame(77, $publisher->published[0]['data']['entity_id']);
        $this->assertSame(77, $publisher->published[0]['data']['order_id']);
        $this->assertSame('INV-100', $publisher->published[0]['data']['increment_id']);
        $this->assertSame(250.0, $publisher->published[0]['data']['grand_total']);
    }

    public function testDoesNotFireWithoutInvoiceInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new InvoicePaidObserver($publisher, new RecordingLogger());

        $observer->execute($this->event(null));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutOrderId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new InvoicePaidObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->invoice(0)));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new InvoicePaidObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->event($this->invoice(77, 'INV-100')));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('sales.invoice.paid', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
        $this->assertStringContainsString('INV-100', $logger->allMessages());
    }
}
