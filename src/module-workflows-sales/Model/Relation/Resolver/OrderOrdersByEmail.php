<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Relation\Resolver;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\RelationInterface;
use MageOS\Workflows\Model\Relation\Resolver\OrderIdCollector;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * `order.orders_by_email` — the OTHER orders sharing this order's
 * `customer_email` (docs/discovery/entity-cross-referencing.md §4): guest
 * repeat-buyer detection ("this email has ≥ 2 prior orders"). Self is
 * excluded, canceled orders are excluded (consistent with
 * CustomerAggregateProvider), and the ids come back newest-first so the
 * RelationContext cap keeps the most recent.
 *
 * The excluded-state list is fixed in code, not merchant-configurable in v1
 * (discovery §9): the relation's meaning must be stable across installs.
 */
class OrderOrdersByEmail implements RelationInterface
{
    /**
     * Order states this relation never counts (open question §9: exclude
     * canceled, keep everything else — a completed/closed order is still a
     * prior purchase).
     */
    private const EXCLUDED_STATES = [Order::STATE_CANCELED];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getCode(): string
    {
        return 'order.orders_by_email';
    }

    public function getLabel(): string
    {
        return (string) __('other orders with the same email');
    }

    public function getSourceEntityType(): string
    {
        return HydrationProviderInterface::TYPE_ORDER;
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
        $email = trim((string) $source->getData('customer_email'));
        if ($email === '') {
            return [];
        }
        $selfId = (int) ($source->getData('entity_id') ?: 0);

        $this->searchCriteriaBuilder->addFilter('customer_email', strtolower($email), 'eq');
        $this->searchCriteriaBuilder->addFilter('state', self::EXCLUDED_STATES, 'nin');
        if ($selfId > 0) {
            $this->searchCriteriaBuilder->addFilter('entity_id', $selfId, 'neq');
        }
        $result = $this->orderRepository->getList($this->searchCriteriaBuilder->create());

        return OrderIdCollector::newestFirst($result->getItems());
    }
}
