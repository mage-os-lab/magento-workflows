<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Model\Rule\Hydrator;

use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * PRD-C2 — product review aggregates contributed to the catalog_product
 * condition root through AggregateProviderPool under entity type
 * 'catalog_product'. Placement rule 3: the queried data (reviews / ratings) is
 * review-owned, so the provider lives in the review pack; the catalog pack's
 * product root reads it only through the engine pool (no cross-pack require).
 * When this pack is absent the leaves simply are not offered.
 *
 *   - reviews_count  (numeric)  count of APPROVED product reviews;
 *   - rating_percent (numeric)  average approved rating as a 0-100 percent.
 *
 * DATA SOURCE (the honest cheap read, documented):
 * Rather than the store-scoped, aggregation-cron-dependent
 * review_entity_summary table, both values are computed directly from the base
 * tables with PLAIN-COLUMN reads (no SQL aggregate expressions), so the numbers
 * reflect the live moderation state and the read is portable:
 *
 *   - reviews_count: COUNT of `review` rows for this product (entity_id = 1,
 *     the product review entity type) whose status_id is Approved.
 *   - rating_percent: the mean of `rating_option_vote.percent` (each vote is a
 *     0-100 value) across the votes belonging to this product's APPROVED
 *     reviews — the same per-vote-percent average Magento's own summary uses.
 *
 * STORE SCOPE (documented limitation, analogous to inventory's default-stock
 * salable_qty): approval (review.status_id) is a GLOBAL review property, and a
 * hydration has only a product id in scope — no store/website context. These
 * aggregates therefore count a review as soon as it is Approved, IGNORING
 * per-store review visibility (the review_store assignment). On a single-store
 * store (and for reviews visible on all stores, the common case) this is exact;
 * on a multi-store store a review approved but shown on a subset of stores
 * still counts. There is no per-scope condition-evaluation seam in v1; this is
 * the pragmatic choice — correct for the common case, explicit about the edge.
 *
 * Absence (fail-toward-false): a product with NO approved reviews contributes
 * NEITHER key (not a zero/na row). Absent attributes only match the negative
 * operators (AbstractWorkflowCondition::validateAttribute()), so "reviews_count
 * >= 3" and "rating_percent >= 80" both correctly miss an unreviewed product.
 * These attributes never appear in a trigger snapshot, so conditions on them
 * always classify as needs_hydration and resolve in phase 2.
 */
class ProductReviewAggregateProvider implements AggregateProviderInterface
{
    /**
     * Review entity-type row id for products in stock data (1). Consistent with
     * the review observers' lookup-free product check.
     */
    private const PRODUCT_ENTITY_TYPE_ID = 1;

    private const ATTRIBUTE_METADATA = [
        'reviews_count' => ['label' => 'Reviews: Approved Count', 'input_type' => 'numeric'],
        'rating_percent' => ['label' => 'Reviews: Approved Rating Percent', 'input_type' => 'numeric'],
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
     * @return array{reviews_count?: int, rating_percent?: float}
     */
    public function getAggregates(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $reviewsCount = $this->countApprovedReviews($productId);
        if ($reviewsCount <= 0) {
            // No approved reviews: contribute nothing (fail-toward-false).
            return [];
        }

        $aggregates = ['reviews_count' => $reviewsCount];

        $ratingPercent = $this->averageApprovedRatingPercent($productId);
        if ($ratingPercent !== null) {
            $aggregates['rating_percent'] = $ratingPercent;
        }

        return $aggregates;
    }

    private function countApprovedReviews(int $productId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['r' => $this->resourceConnection->getTableName('review')],
                ['status_id']
            )
            ->where('r.entity_pk_value = ?', $productId)
            ->where('r.entity_id = ?', self::PRODUCT_ENTITY_TYPE_ID)
            ->where('r.status_id = ?', Review::STATUS_APPROVED);

        return count($connection->fetchAll($select));
    }

    /**
     * Mean vote percent (0-100) across this product's approved reviews' votes,
     * or null when there are no such votes.
     */
    private function averageApprovedRatingPercent(int $productId): ?float
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['rov' => $this->resourceConnection->getTableName('rating_option_vote')],
                ['percent']
            )
            ->join(
                ['r' => $this->resourceConnection->getTableName('review')],
                'r.review_id = rov.review_id',
                []
            )
            ->where('rov.entity_pk_value = ?', $productId)
            ->where('r.status_id = ?', Review::STATUS_APPROVED);

        $rows = $connection->fetchAll($select);
        if ($rows === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) ($row['percent'] ?? 0);
        }
        return $sum / count($rows);
    }
}
