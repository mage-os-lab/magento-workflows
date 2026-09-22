<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Api;

interface RefundOrderInterface
{
    public function execute(int $orderId, array $items, bool $notify): int;
}
