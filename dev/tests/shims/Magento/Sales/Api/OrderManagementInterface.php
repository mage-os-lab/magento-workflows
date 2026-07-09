<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

/**
 * Standalone-runner shim for Magento\Sales\Api\OrderManagementInterface. Only
 * notify() — the seam order.send_email (ORD-A2, order_confirmation mode) uses to
 * resend the order email — is declared; test doubles implement it directly.
 */
interface OrderManagementInterface
{
    /**
     * @param int $id
     * @return bool
     */
    public function notify($id);
}
