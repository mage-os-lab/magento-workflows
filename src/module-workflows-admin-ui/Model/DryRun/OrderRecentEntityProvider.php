<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsAdminUi\Model\DryRun;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use MageOS\Workflows\Api\RecentEntityProviderInterface;

/**
 * Recent sales orders for the dry-run entity picker (03): newest first, labelled
 * by increment id and grand total. v1 does no condition filtering — it is a
 * convenience shortcut, and manual id entry remains available for anything not
 * in the list.
 */
class OrderRecentEntityProvider implements RecentEntityProviderInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    public function getEntityType(): string
    {
        return 'sales_order';
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
        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            /** @var OrderInterface $order */
            $rows[] = [
                'id' => (int) $order->getEntityId(),
                'label' => sprintf(
                    '#%s — %s',
                    (string) $order->getIncrementId(),
                    (string) $order->getGrandTotal()
                ),
            ];
        }
        return $rows;
    }
}
