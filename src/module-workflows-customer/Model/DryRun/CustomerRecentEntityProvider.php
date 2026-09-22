<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\DryRun;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use MageOS\Workflows\Api\RecentEntityProviderInterface;

/**
 * Recent customers for the dry-run entity picker (03): newest first, labelled
 * by email and name. v1 does no condition filtering — it is a convenience
 * shortcut, and manual id entry remains available for anything not in the list
 * (same contract as the sales_order provider).
 */
class CustomerRecentEntityProvider implements RecentEntityProviderInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    public function getEntityType(): string
    {
        return 'customer';
    }

    public function getRecent(int $limit): array
    {
        $sortOrder = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDirection('DESC')
            ->create();

        $criteria = $this->searchCriteriaBuilder
            ->addSortOrder($sortOrder)
            ->setPageSize($limit)
            ->setCurrentPage(1)
            ->create();

        $rows = [];
        foreach ($this->customerRepository->getList($criteria)->getItems() as $customer) {
            /** @var CustomerInterface $customer */
            $email = (string) $customer->getEmail();
            $name = trim(sprintf(
                '%s %s',
                (string) $customer->getFirstname(),
                (string) $customer->getLastname()
            ));
            $rows[] = [
                'id' => (int) $customer->getId(),
                'label' => $name === '' ? $email : sprintf('%s — %s', $email, $name),
            ];
        }
        return $rows;
    }
}
