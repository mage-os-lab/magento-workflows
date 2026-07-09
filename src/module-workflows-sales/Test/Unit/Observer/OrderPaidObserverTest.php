<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use MageOS\WorkflowsSales\Observer\OrderPaidObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the paid-transition contract (ORD-T1): 'sales.order.paid' is published
 * exactly ONCE, on the transition into fully paid (base amounts compared on
 * both sides), with total_paid / grand_total / payment method in the payload.
 * A save that keeps the order already-paid does not re-fire; a zero-total order
 * counts as paid on its first save and never again; a publish failure is logged
 * and never breaks the order save.
 */
class OrderPaidObserverTest extends TestCase
{
    /**
     * @param float|null $origBasePaid null models a brand-new order (no orig data)
     */
    private function order(
        float $baseGrandTotal,
        float $baseTotalPaid,
        ?float $origBasePaid,
        float $grandTotal = 0.0,
        float $totalPaid = 0.0,
        ?string $paymentMethod = 'checkmo',
        int $entityId = 11,
        string $increment = '100000011'
    ): Order {
        return new class(
            $baseGrandTotal,
            $baseTotalPaid,
            $origBasePaid,
            $grandTotal,
            $totalPaid,
            $paymentMethod,
            $entityId,
            $increment
        ) extends Order {
            public function __construct(
                private readonly float $baseGrandTotal,
                private readonly float $baseTotalPaid,
                private readonly ?float $origBasePaid,
                private readonly float $grandTotalV,
                private readonly float $totalPaidV,
                private readonly ?string $paymentMethod,
                private readonly int $id,
                private readonly string $increment
            ) {
            }

            public function getEntityId(): int
            {
                return $this->id;
            }

            public function getIncrementId(): string
            {
                return $this->increment;
            }

            /**
             * @param string|null $key
             * @return mixed
             */
            public function getData($key = '', $index = null)
            {
                return match ($key) {
                    'base_grand_total' => $this->baseGrandTotal,
                    'base_total_paid' => $this->baseTotalPaid,
                    'grand_total' => $this->grandTotalV,
                    'total_paid' => $this->totalPaidV,
                    default => null,
                };
            }

            /**
             * @param string|null $key
             * @return mixed
             */
            public function getOrigData($key = null)
            {
                return $key === 'base_total_paid' ? $this->origBasePaid : null;
            }

            /**
             * @return object|null
             */
            public function getPayment()
            {
                if ($this->paymentMethod === null) {
                    return null;
                }
                return new class($this->paymentMethod) {
                    public function __construct(private readonly string $method)
                    {
                    }
                    public function getMethod(): string
                    {
                        return $this->method;
                    }
                };
            }
        };
    }

    private function event(?Order $order): Observer
    {
        return new Observer(['event' => new Event($order === null ? [] : ['order' => $order])]);
    }

    public function testPublishesOnceOnTheTransitionToFullyPaid(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        // orig base_total_paid 0 (< 100) -> new 100 (>= 100): a real transition.
        $observer->execute($this->event($this->order(
            baseGrandTotal: 100.0,
            baseTotalPaid: 100.0,
            origBasePaid: 0.0,
            grandTotal: 120.0,
            totalPaid: 120.0,
            paymentMethod: 'authorizenet'
        )));

        $this->assertCount(1, $publisher->published);
        $this->assertSame('sales.order.paid', $publisher->published[0]['event']);
        $this->assertSame(11, $publisher->published[0]['data']['id']);
        $this->assertSame(11, $publisher->published[0]['data']['entity_id']);
        $this->assertSame(120.0, $publisher->published[0]['data']['total_paid']);
        $this->assertSame(120.0, $publisher->published[0]['data']['grand_total']);
        $this->assertSame('authorizenet', $publisher->published[0]['data']['payment_method']);
    }

    public function testDoesNotReFireForAnAlreadyPaidOrder(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        // orig already >= grand_total: a later save of a settled order.
        $observer->execute($this->event($this->order(
            baseGrandTotal: 100.0,
            baseTotalPaid: 100.0,
            origBasePaid: 100.0
        )));

        $this->assertCount(0, $publisher->published, 'transition detection must not re-fire on a settled order');
    }

    public function testDoesNotFireWhenNotYetFullyPaid(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        // Partial capture: 40 of 100.
        $observer->execute($this->event($this->order(
            baseGrandTotal: 100.0,
            baseTotalPaid: 40.0,
            origBasePaid: 0.0
        )));

        $this->assertCount(0, $publisher->published);
    }

    public function testZeroTotalOrderCountsAsPaidOnFirstSave(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        // grand_total 0, new order (orig base_total_paid absent): 0 >= 0 -> paid.
        $observer->execute($this->event($this->order(
            baseGrandTotal: 0.0,
            baseTotalPaid: 0.0,
            origBasePaid: null,
            grandTotal: 0.0,
            totalPaid: 0.0,
            paymentMethod: 'free'
        )));

        $this->assertCount(1, $publisher->published, 'a zero-total order is settled on first save');
        $this->assertSame('free', $publisher->published[0]['data']['payment_method']);
    }

    public function testZeroTotalOrderDoesNotReFireOnLaterSaves(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        // Second save of the same zero-total order: orig base_total_paid is now 0,
        // which is not < 0, so the transition guard fails.
        $observer->execute($this->event($this->order(
            baseGrandTotal: 0.0,
            baseTotalPaid: 0.0,
            origBasePaid: 0.0
        )));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutPersistedEntityId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->order(
            baseGrandTotal: 100.0,
            baseTotalPaid: 100.0,
            origBasePaid: 0.0,
            entityId: 0
        )));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutOrderInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        $observer->execute($this->event(null));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishesWithNullPaymentMethodWhenNoPayment(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderPaidObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->order(
            baseGrandTotal: 50.0,
            baseTotalPaid: 50.0,
            origBasePaid: 0.0,
            paymentMethod: null
        )));

        $this->assertCount(1, $publisher->published);
        $this->assertNull($publisher->published[0]['data']['payment_method']);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakOrderSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new OrderPaidObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->event($this->order(
            baseGrandTotal: 100.0,
            baseTotalPaid: 100.0,
            origBasePaid: 0.0
        )));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('sales.order.paid', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
        $this->assertStringContainsString('100000011', $logger->allMessages());
    }
}
