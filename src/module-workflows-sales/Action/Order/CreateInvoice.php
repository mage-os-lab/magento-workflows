<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Action\Order;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * order.create_invoice — invoices all invoiceable items, capturing online or
 * offline. canInvoice() guard makes redelivery safe: a fully invoiced order skips.
 */
class CreateInvoice extends AbstractOrderAction implements SimulateableActionInterface
{
    private const CAPTURE_ONLINE = 'online';
    private const CAPTURE_OFFLINE = 'offline';

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory
    ) {
        parent::__construct($orderRepository);
    }

    public function getCode(): string
    {
        return 'order.create_invoice';
    }

    public function getLabel(): string
    {
        return (string)__('Create Invoice');
    }

    public function getConfigForm(): array
    {
        return [
            [
                'name' => 'capture',
                'label' => 'Capture Mode',
                'type' => 'select',
                'required' => false,
                'default' => self::CAPTURE_OFFLINE,
                'options' => [
                    ['value' => self::CAPTURE_OFFLINE, 'label' => 'Capture Offline'],
                    ['value' => self::CAPTURE_ONLINE, 'label' => 'Capture Online'],
                ],
            ],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $capture = $this->stringConfig($config, 'capture', self::CAPTURE_OFFLINE);
        if (!in_array($capture, [self::CAPTURE_ONLINE, self::CAPTURE_OFFLINE], true)) {
            return ActionResult::failure((string)__('Invalid capture mode "%1" (online|offline)', $capture));
        }

        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }

        if (!$order->canInvoice()) {
            return ActionResult::skipped(sprintf(
                'Order %s cannot be invoiced (state "%s")',
                $order->getIncrementId(),
                $order->getState()
            ));
        }

        try {
            $invoice = $this->invoiceService->prepareInvoice($order);
            if (!$invoice->getTotalQty()) {
                return ActionResult::skipped('No invoiceable items on order');
            }
            $invoice->setRequestedCaptureCase(
                $capture === self::CAPTURE_ONLINE ? Invoice::CAPTURE_ONLINE : Invoice::CAPTURE_OFFLINE
            );
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);

            $this->transactionFactory->create()
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();
        } catch (LocalizedException $e) {
            // Configuration/state problems will not resolve on redelivery
            return ActionResult::failure('Could not create invoice: ' . $e->getMessage());
        } catch (\Exception $e) {
            // Gateway/network flakiness on online capture may succeed on retry
            return ActionResult::failure('Could not create invoice: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'invoice_id' => (int)$invoice->getEntityId(),
            'invoice_increment_id' => (string)$invoice->getIncrementId(),
            'grand_total' => (float)$invoice->getGrandTotal(),
            'capture' => $capture,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $order = $this->loadOrder($ctx);
        if ($order instanceof ActionResult) {
            return $order;
        }
        if (!$order->canInvoice()) {
            return ActionResult::skipped(sprintf('Order %s cannot be invoiced', $order->getIncrementId()));
        }
        $capture = $this->stringConfig($config, 'capture', self::CAPTURE_OFFLINE);
        return $this->simulated(sprintf(
            'Create invoice for order %s (capture %s)',
            $order->getIncrementId(),
            $capture
        ));
    }
}
