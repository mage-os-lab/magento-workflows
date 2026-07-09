<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Model\Rule\Hydrator;

use Magento\Review\Model\Review;
use MageOS\WorkflowsReview\Model\Rule\Hydrator\ProductReviewAggregateProvider;
use MageOS\WorkflowsReview\Test\Unit\Stub\FakeReviewDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * PRD-C2: product review aggregates on the catalog_product root. reviews_count
 * counts APPROVED product reviews; rating_percent is the mean vote percent
 * across those approved reviews. A product with no approved reviews contributes
 * NEITHER key (fail-toward-false).
 */
class ProductReviewAggregateProviderTest extends TestCase
{
    private function provider(FakeReviewDb $db): ProductReviewAggregateProvider
    {
        return new ProductReviewAggregateProvider(new FakeResourceConnection($db));
    }

    public function testMetadataAdvertisesBothNumericAggregates(): void
    {
        $metadata = $this->provider(new FakeReviewDb())->getAttributeMetadata();

        $this->assertArrayHasKey('reviews_count', $metadata);
        $this->assertArrayHasKey('rating_percent', $metadata);
        $this->assertSame('numeric', $metadata['reviews_count']['input_type']);
        $this->assertSame('numeric', $metadata['rating_percent']['input_type']);
        $this->assertSame('Reviews: Approved Count', $metadata['reviews_count']['label']);
    }

    public function testCountsOnlyApprovedReviewsAndAveragesTheirVotes(): void
    {
        $db = new FakeReviewDb();
        // Product 55: two approved reviews (100% and 60%) and one pending (ignored).
        $db->addReview(1, 55, Review::STATUS_APPROVED);
        $db->addReview(2, 55, Review::STATUS_APPROVED);
        $db->addReview(3, 55, Review::STATUS_PENDING);
        $db->addVote(1, 55, 100.0);
        $db->addVote(2, 55, 60.0);
        $db->addVote(3, 55, 20.0); // pending review's vote must not count
        // A different product's approved review must not leak in.
        $db->addReview(4, 77, Review::STATUS_APPROVED);
        $db->addVote(4, 77, 10.0);

        $aggregates = $this->provider($db)->getAggregates(55);

        $this->assertSame(2, $aggregates['reviews_count']);
        $this->assertSame(80.0, $aggregates['rating_percent']);
    }

    public function testNoApprovedReviewsYieldsNoAggregates(): void
    {
        $db = new FakeReviewDb();
        $db->addReview(1, 55, Review::STATUS_PENDING);
        $db->addReview(2, 55, Review::STATUS_NOT_APPROVED);

        $this->assertSame([], $this->provider($db)->getAggregates(55), 'no approved reviews => absent');
    }

    public function testApprovedReviewsWithoutVotesOmitsRatingPercentOnly(): void
    {
        $db = new FakeReviewDb();
        $db->addReview(1, 55, Review::STATUS_APPROVED);

        $aggregates = $this->provider($db)->getAggregates(55);

        $this->assertSame(1, $aggregates['reviews_count']);
        $this->assertFalse(array_key_exists('rating_percent', $aggregates), 'no votes => rating_percent absent');
    }

    public function testInvalidProductIdShortCircuits(): void
    {
        $this->assertSame([], $this->provider(new FakeReviewDb())->getAggregates(0));
    }
}
