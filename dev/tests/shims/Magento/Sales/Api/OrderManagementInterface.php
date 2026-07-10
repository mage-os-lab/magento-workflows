<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\OrderManagementInterface.
 * Mirrors the FULL real interface (8 methods) with loose param signatures so a
 * partial double is caught here, not only under real Magento. Only notify() is
 * exercised by order.send_email (ORD-A2).
 */
interface OrderManagementInterface
{
    public function cancel($id);

    public function getCommentsList($id);

    public function addComment($id, $statusHistory);

    public function notify($id);

    public function getStatus($id);

    public function hold($id);

    public function unHold($id);

    public function place($order);
}
