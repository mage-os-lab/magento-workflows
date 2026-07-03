<?php
declare(strict_types=1);

namespace Magento\Newsletter\Model;

interface SubscriptionManagerInterface
{
    public function unsubscribeCustomer(int $customerId, int $storeId);
}
