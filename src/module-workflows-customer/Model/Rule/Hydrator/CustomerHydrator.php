<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Rule\Hydrator;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\Workflows\Model\Rule\Hydrator\EntityDataConverter;
use MageOS\Workflows\Model\Rule\Hydrator\EntityHydratorInterface;

/**
 * customer hydrator: flat customer DTO data with custom/extension attributes
 * lifted to top-level keys, enriched with aggregate attributes contributed to
 * the customer root through AggregateProviderPool (E2) — order-history totals
 * (orders_count, lifetime_sales, avg_order_value, last_order_at,
 * days_since_last_order) from CustomerAggregateProvider today, plus whatever
 * later packs register for the customer entity type.
 *
 * The aggregates exist ONLY on hydrated customers — trigger snapshots come
 * from trigger payloads and never carry them, so conditions on aggregate
 * attributes always classify as needs_hydration and resolve in phase 2.
 */
class CustomerHydrator implements EntityHydratorInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly EntityDataConverter $dataConverter,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly AggregateProviderPool $aggregateProviderPool
    ) {
    }

    public function hydrate(int $entityId): ?DataObject
    {
        try {
            $customer = $this->customerRepository->getById($entityId);
        } catch (NoSuchEntityException | LocalizedException) {
            return null;
        }

        $data = array_merge(
            $this->dataConverter->toFlatArray($customer, CustomerInterface::class),
            $this->aggregateProviderPool->getAggregates(HydrationProviderInterface::TYPE_CUSTOMER, $entityId)
        );

        return $this->dataObjectFactory->create(['data' => $data]);
    }
}
