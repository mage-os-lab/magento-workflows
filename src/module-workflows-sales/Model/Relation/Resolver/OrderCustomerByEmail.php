<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Relation\Resolver;

use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * `order.customer_by_email` — the customer whose account email matches the
 * order's `customer_email`, website-scoped per account-share mode
 * (docs/discovery/entity-cross-referencing.md §4). The flagship guest check:
 * "order placed as guest → NOT EXISTS a customer with this email".
 */
class OrderCustomerByEmail extends AbstractCustomerByEmail
{
    public function getCode(): string
    {
        return 'order.customer_by_email';
    }

    public function getLabel(): string
    {
        return (string) __('a customer account matching the order email');
    }

    public function getSourceEntityType(): string
    {
        return HydrationProviderInterface::TYPE_ORDER;
    }
}
