<?php
declare(strict_types=1);

namespace Magento\Sales\Api\Data;

/**
 * Standalone-runner shim for Magento\Sales\Api\Data\InvoiceInterface.
 * Marker only: the real InvoiceService::prepareInvoice() return type (and
 * therefore signature-faithful test doubles) reference it, and the shim
 * Invoice model implements it so those doubles' return values type-check.
 */
interface InvoiceInterface
{
}
