<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\DryRun;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use MageOS\Workflows\Api\RecentEntityProviderInterface;

/**
 * Recent quotes (carts) for the dry-run entity picker (03): newest first,
 * labelled by id, customer email (or "Guest") and grand total. v1 does no
 * condition filtering — it is a convenience shortcut, and manual id entry
 * remains available for anything not in the list (same contract as the
 * sales_order provider).
 *
 * Email and grand total are Quote-MODEL data, not CartInterface data (the Api
 * DTO carries neither), so they are read behind an `instanceof Quote` guard —
 * the same shape QuoteHydrator uses for the shipping-address extras.
 */
class QuoteRecentEntityProvider implements RecentEntityProviderInterface
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    public function getEntityType(): string
    {
        return 'quote';
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
        foreach ($this->cartRepository->getList($criteria)->getItems() as $quote) {
            /** @var CartInterface $quote */
            $email = $quote instanceof Quote ? (string) $quote->getCustomerEmail() : '';
            $total = $quote instanceof Quote ? (string) $quote->getGrandTotal() : '';
            $label = sprintf('#%d — %s', (int) $quote->getId(), $email === '' ? 'Guest' : $email);
            if ($total !== '') {
                $label .= ' — ' . $total;
            }
            $rows[] = [
                'id' => (int) $quote->getId(),
                'label' => $label,
            ];
        }
        return $rows;
    }
}
