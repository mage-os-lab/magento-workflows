<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects the TRANSITION to fully paid on
 * 'sales_order_save_after' and publishes the 'sales.order.paid' async event
 * (declared in etc/async_events.xml). Payment completion is NOT reliably a
 * status change on every payment method, so a status-change trigger cannot
 * substitute — this is the anchor event for post-payment flows (fulfillment
 * kickoff, "paid but not shipped in 48h" SLAs).
 *
 * Transition detection (fires exactly once):
 *   orig base_total_paid < base_grand_total (or no original value) AND
 *   new  base_total_paid >= base_grand_total.
 * BASE amounts are compared on both sides — never base vs. order-currency — so
 * the comparison is currency-consistent regardless of the order's display
 * currency (payment capture is recorded in base currency). Because the guard
 * requires the ORIGINAL value to be below grand_total, a subsequent save of an
 * already-paid order (orig >= grand_total) fails the guard and does not
 * re-fire: state detection would fire on every save, transition detection
 * fires once.
 *
 * Zero-total edge: a grand_total = 0 order (fully free / 100%-discounted /
 * zero-total virtual) has base_total_paid 0 >= base_grand_total 0 the first
 * time it is persisted, while its original base_total_paid is absent — so it
 * counts as PAID on first save and publishes once. On every later save the
 * original value is 0, which is not < 0, so it never re-fires. This is the
 * intended behavior: a zero-total order is settled the moment it exists.
 *
 * Loop-guard/debounce: publishing rides 'sales_order_save_after' exactly like
 * OrderStatusChangeObserver; the once-only transition guard is itself the
 * debounce (no additional flag table needed). Actions that re-save the order
 * do not re-trigger because they leave base_total_paid at or above
 * grand_total. Publishing failures are logged and never break the order save.
 *
 * The payload carries the order-currency total_paid / grand_total (the
 * merchant-facing figures) plus the payment method code as extras; the order
 * itself is hydrated by OrderRepositoryInterface::get from the 'id' key.
 */
class OrderPaidObserver implements ObserverInterface
{
    public const EVENT_NAME = 'sales.order.paid';

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
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$order->getEntityId()) {
            return;
        }

        $grandTotal = (float)$order->getData('base_grand_total');
        $newPaid = (float)$order->getData('base_total_paid');

        $origPaidRaw = $order->getOrigData('base_total_paid');
        // A brand-new order has no original value: treat it as "was not paid"
        // so a zero-total order settles on first save, and a first payment
        // capture on a normal order transitions cleanly.
        $wasPaid = $origPaidRaw !== null && (float)$origPaidRaw >= $grandTotal;
        $isPaid = $newPaid >= $grandTotal;

        if ($wasPaid || !$isPaid) {
            return;
        }

        try {
            $payment = $order->getPayment();
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'id' hydrates the payload via OrderRepositoryInterface::get($id)
                'id' => (int)$order->getEntityId(),
                'entity_id' => (int)$order->getEntityId(),
                'total_paid' => (float)$order->getData('total_paid'),
                'grand_total' => (float)$order->getData('grand_total'),
                'payment_method' => $payment !== null ? (string)$payment->getMethod() : null,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for order #%s: %s',
                    self::EVENT_NAME,
                    $order->getIncrementId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
