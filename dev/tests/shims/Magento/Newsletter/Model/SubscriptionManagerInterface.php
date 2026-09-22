<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Newsletter\Model;

interface SubscriptionManagerInterface
{
    public function unsubscribeCustomer(int $customerId, int $storeId);
}
