<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects invoice payment and publishes the
 * 'sales.invoice.paid' async event (declared in etc/async_events.xml).
 *
 * Core dispatch site: Magento\Sales\Model\Order\Invoice::pay() dispatches the
 * framework event 'sales_order_invoice_pay' (event object key 'invoice') once,
 * guarded by wasPayCalled(), after crediting the order's total_paid. pay() is
 * reached from Invoice::register() (offline invoices) and from the online
 * capture path via InvoiceService — so this observer fires when an invoice is
 * settled by either route.
 *
 * Mirrors the existing upstream sales.invoice.created contract EXACTLY: the
 * trigger metadata keeps entity="sales_order" and the async event hydrates the
 * parent ORDER through Magento\Sales\Api\OrderRepositoryInterface::get (the
 * document itself is not a condition root — its fields ride the payload). The
 * published 'id' is therefore the ORDER id, and invoice-specific fields
 * (increment_id, grand_total, order_id) travel as payload extras.
 *
 * Overlap with sales.invoice.created (documented honestly): an OFFLINE invoice
 * pays immediately at creation, so sales.invoice.created and sales.invoice.paid
 * fire back-to-back for it. sales.invoice.paid earns its keep on the ONLINE
 * capture path, where creation and successful capture can be separated in time
 * (deferred/async capture) — "paid" is the moment funds are actually captured,
 * which "created" does not guarantee.
 *
 * Loop-guard/debounce: pay() is called at most once per invoice
 * (wasPayCalled() guard in core), so this is inherently once-per-invoice; no
 * flag table is needed. Publishing failures are logged and never break the
 * invoice/order save.
 */
class InvoicePaidObserver implements ObserverInterface
{
    public const EVENT_NAME = 'sales.invoice.paid';

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
        $invoice = $observer->getEvent()->getData('invoice');
        if (!$invoice instanceof Invoice) {
            return;
        }

        $orderId = (int)$invoice->getOrderId();
        if ($orderId <= 0) {
            return;
        }

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'id' hydrates the parent order via OrderRepositoryInterface::get($id)
                'id' => $orderId,
                'entity_id' => $orderId,
                'order_id' => $orderId,
                'increment_id' => (string)$invoice->getIncrementId(),
                'grand_total' => (float)$invoice->getGrandTotal(),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for invoice #%s: %s',
                    self::EVENT_NAME,
                    $invoice->getIncrementId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
