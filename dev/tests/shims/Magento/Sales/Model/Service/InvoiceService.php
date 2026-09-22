<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
     * Signature mirrors the real class (param name + InvoiceInterface
     * return type) so doubles written against this shim stay drop-in
     * compatible under real PHPUnit.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $orderItemsQtyToInvoice
     * @return \Magento\Sales\Api\Data\InvoiceInterface
     */
    public function prepareInvoice(
        \Magento\Sales\Model\Order $order,
        array $orderItemsQtyToInvoice = []
    ): \Magento\Sales\Api\Data\InvoiceInterface {
        throw new \RuntimeException('prepareInvoice() not implemented in shim');
    }
}
