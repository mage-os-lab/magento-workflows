<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Option;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use MageOS\Workflows\Model\Option\AbstractOptionSource;

/**
 * Bounded option source: customer group id => code (F6). Enumerable in full,
 * referenced with min_chars: 0 or inlined by an action.
 */
class CustomerGroupOptionSource extends AbstractOptionSource
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function getCode(): string
    {
        return 'customer_groups';
    }

    /**
     * @inheritDoc
     */
    protected function loadOptions(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $options = [];
        foreach ($this->groupRepository->getList($searchCriteria)->getItems() as $group) {
            $options[] = [
                'value' => (string) $group->getId(),
                'label' => (string) $group->getCode(),
            ];
        }
        return $options;
    }
}
