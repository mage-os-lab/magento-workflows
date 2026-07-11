<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Relation\Resolver;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\Resolver\OrderIdCollector;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * `customer.open_orders` — a customer's currently-open orders by
 * `customer_id`, state in {new, processing, holded}
 * (docs/discovery/entity-cross-referencing.md §4). Conditions today ("has an
 * open order over $500"); a fan-out target later ("act on each open order").
 *
 * The open-state list is fixed in code, not merchant-configurable in v1
 * (discovery §9). Newest-first so the RelationContext cap keeps the most
 * recent open orders.
 */
class CustomerOpenOrders implements RelationInterface
{
    /**
     * States that count as "open" for this relation.
     */
    private const OPEN_STATES = [Order::STATE_NEW, Order::STATE_PROCESSING, Order::STATE_HOLDED];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getCode(): string
    {
        return 'customer.open_orders';
    }

    public function getLabel(): string
    {
        return (string) __("the customer's open orders");
    }

    public function getSourceEntityType(): string
    {
        return HydrationProviderInterface::TYPE_CUSTOMER;
    }

    public function getTargetEntityType(): string
    {
        return HydrationProviderInterface::TYPE_ORDER;
    }

    public function getCardinality(): string
    {
        return self::CARDINALITY_MANY;
    }

    public function resolveIds(DataObject $source, ?int $websiteId): array
    {
        $customerId = (int) ($source->getData('entity_id') ?: 0);
        if ($customerId <= 0) {
            return [];
        }

        $this->searchCriteriaBuilder->addFilter('customer_id', $customerId, 'eq');
        $this->searchCriteriaBuilder->addFilter('state', self::OPEN_STATES, 'in');
        $result = $this->orderRepository->getList($this->searchCriteriaBuilder->create());

        return OrderIdCollector::newestFirst($result->getItems());
    }
}
