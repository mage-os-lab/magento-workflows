<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Relation\Resolver;

use Magento\Framework\DataObject;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * `order.customer` — an order's registered customer via the `customer_id`
 * foreign key (docs/discovery/entity-cross-referencing.md §4). Formalizes the
 * relation the hardcoded Customer\Combine subtree already traverses; needs no
 * query (the id is a column on the source) and never matches guest orders
 * (`customer_id <= 0`).
 */
class OrderCustomer implements RelationInterface
{
    public function getCode(): string
    {
        return 'order.customer';
    }

    public function getLabel(): string
    {
        return (string) __("the order's registered customer");
    }

    public function getSourceEntityType(): string
    {
        return HydrationProviderInterface::TYPE_ORDER;
    }

    public function getTargetEntityType(): string
    {
        return HydrationProviderInterface::TYPE_CUSTOMER;
    }

    public function getCardinality(): string
    {
        return self::CARDINALITY_ONE;
    }

    public function resolveIds(DataObject $source, ?int $websiteId): array
    {
        $customerId = (int) ($source->getData('customer_id') ?: 0);
        return $customerId > 0 ? [$customerId] : [];
    }
}
