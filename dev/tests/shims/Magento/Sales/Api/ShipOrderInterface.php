<?php
declare(strict_types=1);

namespace Magento\Sales\Api;

interface ShipOrderInterface
{
    public function execute(int $orderId, array $items, bool $notify): int;
}
