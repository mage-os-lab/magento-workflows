<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

interface OrderRepositoryInterface
{
    public function get(int $orderId);
}
