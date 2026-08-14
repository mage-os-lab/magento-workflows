<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Stub;

use Magento\Review\Model\Review;
use MageOS\WorkflowsReview\Model\ReviewPayloadEnricher;

/**
 * Canned enrichment context; skips the real constructor (no DB / customer
 * repository in the standalone runner).
 */
class StubReviewPayloadEnricher extends ReviewPayloadEnricher
{
    /**
     * @param array{rating: int|null, customer_id: int|null, customer_email: string|null} $context
     */
    public function __construct(
        private readonly array $context = ['rating' => null, 'customer_id' => null, 'customer_email' => null]
    ) {
    }

    /**
     * @inheritDoc
     */
    public function enrich(Review $review): array
    {
        return $this->context;
    }
}
