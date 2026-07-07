<?php
declare(strict_types=1);

namespace Magento\Sales\Model\Service;

/**
 * Standalone-runner shim for Magento\Sales\Model\Service\InvoiceService.
 * Preparing a real invoice needs converters and repositories, so it throws
 * unless a test subclass overrides it.
 */
class InvoiceService
{
    /**
     * @param \Magento\Sales\Model\Order $order
     * @param array $qtys
     * @return \Magento\Sales\Model\Order\Invoice
     */
    public function prepareInvoice(\Magento\Sales\Model\Order $order, array $qtys = [])
    {
        throw new \RuntimeException('prepareInvoice() not implemented in shim');
    }
}
