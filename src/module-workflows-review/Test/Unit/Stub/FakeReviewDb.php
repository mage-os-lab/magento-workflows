<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Stub;

use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeSelect;

/**
 * In-memory `review` / `review_detail` / `rating_option_vote` triple backing
 * the review aggregate providers (PRD-C2, CUS-C3). Handed out as the connection
 * by FakeResourceConnection; interprets the RECORDED FakeSelect fragments the
 * providers build (main table + bound where values) so the approved-only and
 * per-product/per-customer semantics under test are the production code's, not
 * the fake's. Only select()/fetchAll() are exercised.
 */
class FakeReviewDb
{
    /** @var array<int, array{entity_pk_value:int, entity_id:int, status_id:int, customer_id:?int}> */
    private array $reviews = [];

    /** @var array<int, array{review_id:int, entity_pk_value:int, percent:float}> */
    private array $votes = [];

    public function addReview(
        int $reviewId,
        int $productId,
        int $statusId,
        ?int $customerId = null,
        int $entityTypeId = 1
    ): void {
        $this->reviews[$reviewId] = [
            'entity_pk_value' => $productId,
            'entity_id' => $entityTypeId,
            'status_id' => $statusId,
            'customer_id' => $customerId,
        ];
    }

    public function addVote(int $reviewId, int $productId, float $percent): void
    {
        $this->votes[] = [
            'review_id' => $reviewId,
            'entity_pk_value' => $productId,
            'percent' => $percent,
        ];
    }

    public function select(): FakeSelect
    {
        return new FakeSelect();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll($select, $bind = [], $fetchMode = null): array
    {
        if (!$select instanceof FakeSelect) {
            throw new \BadMethodCallException('expected a FakeSelect');
        }
        $table = $select->mainTableName();

        if ($table === 'review') {
            // Product reviews_count: approved product reviews for the product.
            $productId = (int) $select->whereValue('entity_pk_value');
            $status = (int) $select->whereValue('status_id');
            $entityType = (int) $select->whereValue('entity_id');
            $rows = [];
            foreach ($this->reviews as $review) {
                if ($review['entity_pk_value'] === $productId
                    && $review['entity_id'] === $entityType
                    && $review['status_id'] === $status
                ) {
                    $rows[] = ['status_id' => $review['status_id']];
                }
            }
            return $rows;
        }

        if ($table === 'rating_option_vote') {
            // Product rating percent: votes of the product's approved reviews.
            $productId = (int) $select->whereValue('entity_pk_value');
            $status = (int) $select->whereValue('status_id');
            $rows = [];
            foreach ($this->votes as $vote) {
                if ($vote['entity_pk_value'] !== $productId) {
                    continue;
                }
                $review = $this->reviews[$vote['review_id']] ?? null;
                if ($review !== null && $review['status_id'] === $status) {
                    $rows[] = ['percent' => $vote['percent']];
                }
            }
            return $rows;
        }

        if ($table === 'review_detail') {
            // Customer reviews_count: the customer's approved reviews.
            $customerId = (int) $select->whereValue('customer_id');
            $status = (int) $select->whereValue('status_id');
            $rows = [];
            foreach ($this->reviews as $reviewId => $review) {
                if ($review['customer_id'] === $customerId && $review['status_id'] === $status) {
                    $rows[] = ['review_id' => $reviewId];
                }
            }
            return $rows;
        }

        throw new \BadMethodCallException('unexpected table: ' . $table);
    }
}
