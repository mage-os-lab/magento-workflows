<?php
declare(strict_types=1);

namespace Magento\Sales\Model\Order;

/**
 * Standalone-runner shim for Magento\Sales\Model\Order\Invoice. Constant
 * values mirror the real class; behavioural methods throw unless a test
 * subclass overrides them.
 */
class Invoice implements \Magento\Sales\Api\Data\InvoiceInterface
{
    public const CAPTURE_ONLINE = 'online';
    public const CAPTURE_OFFLINE = 'offline';
    public const NOT_CAPTURE = 'not_capture';

    public function getTotalQty()
    {
        throw new \RuntimeException('getTotalQty() not implemented in shim');
    }

    /**
     * @param string $requestedCaptureCase
     * @return $this
     */
    public function setRequestedCaptureCase($requestedCaptureCase)
    {
        throw new \RuntimeException('setRequestedCaptureCase() not implemented in shim');
    }

    /**
     * @return $this
     */
    public function register()
    {
        throw new \RuntimeException('register() not implemented in shim');
    }

    public function getOrder()
    {
        throw new \RuntimeException('getOrder() not implemented in shim');
    }

    public function getEntityId()
    {
        throw new \RuntimeException('getEntityId() not implemented in shim');
    }

    public function getIncrementId()
    {
        throw new \RuntimeException('getIncrementId() not implemented in shim');
    }

    public function getGrandTotal()
    {
        throw new \RuntimeException('getGrandTotal() not implemented in shim');
    }
}
