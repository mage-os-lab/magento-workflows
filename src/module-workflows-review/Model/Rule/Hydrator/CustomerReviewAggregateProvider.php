<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Model\Rule\Hydrator;

use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * CUS-C3 (reviews half) — the customer's review count, contributed to the
 * CUSTOMER condition root through AggregateProviderPool under entity type
 * 'customer'. Registered ALONGSIDE sales' order_history, customer's birthday
 * and newsletter's status providers — the pool keys entity_type => provider[]
 * and merges every provider's aggregates (multi-provider-per-entity is proven).
 *
 * Placement rule 3: review data is review-owned, so this provider lives in the
 * review pack and contributes onto the customer root WITHOUT workflows-customer
 * referencing this pack (or this pack referencing workflows-customer). It reads
 * only the `review` / `review_detail` tables via ResourceConnection (plain-column
 * read, no cross-module class references), so it adds NO composer dependency on
 * magento/module-customer.
 *
 *   - reviews_count (numeric)  the customer's APPROVED product-review count.
 *
 * APPROVED-ONLY (documented choice): the count is deliberately APPROVED reviews
 * only, not all-submitted — the label is "Reviews: Approved Count" to make the
 * choice visible in the picker. Rationale: "reward repeat reviewers" / "trusted
 * contributor" segmentation should count reviews that actually went live, not
 * pending/rejected drafts; it also lines up with the product-side reviews_count
 * (PRD-C2), which is likewise approved-only. The `customer_id` lives on
 * `review_detail`, the moderation `status_id` on `review`, so the two are
 * joined.
 *
 * Absence (fail-toward-false): a customer with zero approved reviews contributes
 * NO key (not a zero row) — absent attributes only match the negative operators
 * (AbstractWorkflowCondition::validateAttribute()). The attribute never appears
 * in a trigger snapshot, so conditions on it always classify as needs_hydration
 * and resolve in phase 2.
 */
class CustomerReviewAggregateProvider implements AggregateProviderInterface
{
    private const ATTRIBUTE_METADATA = [
        'reviews_count' => ['label' => 'Reviews: Approved Count', 'input_type' => 'numeric'],
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array{reviews_count?: int}
     */
    public function getAggregates(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['rd' => $this->resourceConnection->getTableName('review_detail')],
                ['review_id']
            )
            ->join(
                ['r' => $this->resourceConnection->getTableName('review')],
                'r.review_id = rd.review_id',
                []
            )
            ->where('rd.customer_id = ?', $customerId)
            ->where('r.status_id = ?', Review::STATUS_APPROVED);

        $count = count($connection->fetchAll($select));
        if ($count <= 0) {
            // No approved reviews: contribute nothing (fail-toward-false).
            return [];
        }

        return ['reviews_count' => $count];
    }
}
