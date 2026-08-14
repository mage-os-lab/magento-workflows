<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Review\Model\Review;

/**
 * Enriches the review trigger payloads with the reviewer's identity and the
 * review's average rating — neither lives on the review row itself, and both
 * are what merchants actually branch on (see the product-review-triage
 * bundled template).
 *
 * Rating: mean of the review's rating_option_vote values (one row per rating
 * dimension, each 1-5), rounded to the nearest whole star. For the dominant
 * flow — customer submits (pending), merchant approves — the votes were
 * persisted at submission time, so they are present by the time the approval
 * save fires the trigger. A review created approved in the same request that
 * inserts its votes (admin "save as approved") can race the vote insert; the
 * field degrades to null rather than guessing.
 *
 * Every lookup fails soft (null): payload enrichment must never break a
 * review save or suppress the publish itself.
 */
class ReviewPayloadEnricher
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @return array{rating: int|null, customer_id: int|null, customer_email: string|null}
     */
    public function enrich(Review $review): array
    {
        $customerId = $review->getCustomerId();
        $customerId = $customerId !== null && (int) $customerId > 0 ? (int) $customerId : null;

        return [
            'rating' => $this->averageRating((int) $review->getId()),
            'customer_id' => $customerId,
            'customer_email' => $customerId !== null ? $this->customerEmail($customerId) : null,
        ];
    }

    private function averageRating(int $reviewId): ?int
    {
        if ($reviewId <= 0) {
            return null;
        }
        try {
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('rating_option_vote'), ['AVG(`value`)'])
                ->where('review_id = ?', $reviewId);
            $average = $connection->fetchOne($select);
        } catch (\Throwable) {
            return null;
        }

        if ($average === false || $average === null || $average === '') {
            return null;
        }

        return (int) round((float) $average);
    }

    private function customerEmail(int $customerId): ?string
    {
        try {
            $email = $this->customerRepository->getById($customerId)->getEmail();
        } catch (\Throwable) {
            return null;
        }

        return $email !== null && $email !== '' ? (string) $email : null;
    }
}
