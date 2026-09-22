<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Model\Rule\Hydrator;

use Magento\Review\Model\Review;
use MageOS\WorkflowsReview\Model\Rule\Hydrator\CustomerReviewAggregateProvider;
use MageOS\WorkflowsReview\Test\Unit\Stub\FakeReviewDb;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * CUS-C3 (reviews half): the customer's APPROVED review count on the customer
 * root, contributed by the review pack. Approved-only by design (label
 * "Reviews: Approved Count"); a customer with no approved reviews contributes
 * NO key (fail-toward-false).
 */
class CustomerReviewAggregateProviderTest extends TestCase
{
    private function provider(FakeReviewDb $db): CustomerReviewAggregateProvider
    {
        return new CustomerReviewAggregateProvider(new FakeResourceConnection($db));
    }

    public function testMetadataAdvertisesApprovedCount(): void
    {
        $metadata = $this->provider(new FakeReviewDb())->getAttributeMetadata();

        $this->assertArrayHasKey('reviews_count', $metadata);
        $this->assertSame('numeric', $metadata['reviews_count']['input_type']);
        $this->assertSame('Reviews: Approved Count', $metadata['reviews_count']['label']);
    }

    public function testCountsOnlyTheCustomersApprovedReviews(): void
    {
        $db = new FakeReviewDb();
        // Customer 7: two approved, one pending (ignored).
        $db->addReview(1, 55, Review::STATUS_APPROVED, 7);
        $db->addReview(2, 61, Review::STATUS_APPROVED, 7);
        $db->addReview(3, 62, Review::STATUS_PENDING, 7);
        // Another customer's approved review must not leak in.
        $db->addReview(4, 55, Review::STATUS_APPROVED, 9);

        $aggregates = $this->provider($db)->getAggregates(7);

        $this->assertSame(2, $aggregates['reviews_count']);
    }

    public function testNoApprovedReviewsYieldsNoAggregates(): void
    {
        $db = new FakeReviewDb();
        $db->addReview(1, 55, Review::STATUS_PENDING, 7);

        $this->assertSame([], $this->provider($db)->getAggregates(7), 'no approved reviews => absent');
    }

    public function testInvalidCustomerIdShortCircuits(): void
    {
        $this->assertSame([], $this->provider(new FakeReviewDb())->getAggregates(0));
    }
}
