<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Review\Model\Review;
use MageOS\WorkflowsReview\Observer\ReviewStatusChangedObserver;
use MageOS\WorkflowsReview\Test\Unit\Stub\FakeReview;
use MageOS\WorkflowsReview\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsReview\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * REV-T1: review.status_changed fires exactly on a moderation TRANSITION of an
 * existing product review (orig status != new status), carrying from/to ids +
 * labels and the reviewed product. Pins the submitted-vs-changed boundary: a
 * brand-new review (no original status) is a submission, not a change, so it
 * NEVER fires here (that is ReviewSubmittedObserver's job). Same-status saves
 * and non-product reviews do not fire; publish failures are logged, not thrown.
 */
class ReviewStatusChangedObserverTest extends TestCase
{
    private function observerEvent(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    private function review(?int $origStatus, int $newStatus, $entityId = 1, int $productId = 55): FakeReview
    {
        return new FakeReview(21, $newStatus, $origStatus, $entityId, $productId, 'Great product', 'Jane', 2);
    }

    public function testFiresOnPendingToApprovedWithFromAndTo(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            ['object' => $this->review(Review::STATUS_PENDING, Review::STATUS_APPROVED)]
        ));

        $this->assertCount(1, $publisher->published);
        $event = $publisher->published[0];
        $this->assertSame('review.status_changed', $event['event']);
        $this->assertSame(Review::STATUS_PENDING, $event['data']['from_status']);
        $this->assertSame(Review::STATUS_APPROVED, $event['data']['to_status']);
        $this->assertSame('Pending', $event['data']['from_status_label']);
        $this->assertSame('Approved', $event['data']['to_status_label']);
        $this->assertSame(55, $event['data']['productId']);
        $this->assertSame(55, $event['data']['entity_id']);
        $this->assertSame(21, $event['data']['review_id']);
        $this->assertSame('Great product', $event['data']['review_title']);
        $this->assertSame('Jane', $event['data']['review_nickname']);
        $this->assertSame(2, $event['data']['store_id']);
        $this->assertFalse(array_key_exists('rating_summary', $event['data']), 'no rating on the object => key omitted');
    }

    public function testFiresOnApprovedToNotApproved(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            ['data_object' => $this->review(Review::STATUS_APPROVED, Review::STATUS_NOT_APPROVED)]
        ));

        $this->assertCount(1, $publisher->published);
        $this->assertSame('Approved', $publisher->published[0]['data']['from_status_label']);
        $this->assertSame('Not Approved', $publisher->published[0]['data']['to_status_label']);
    }

    public function testNewReviewDoesNotFire(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        // No original status => a submission, owned by ReviewSubmittedObserver.
        $observer->execute($this->observerEvent(
            ['object' => $this->review(null, Review::STATUS_APPROVED)]
        ));

        $this->assertCount(0, $publisher->published, 'new review is not a status change');
    }

    public function testUnchangedStatusDoesNotFire(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            ['object' => $this->review(Review::STATUS_APPROVED, Review::STATUS_APPROVED)]
        ));

        $this->assertCount(0, $publisher->published, 'a save with no status change must not fire');
    }

    public function testNonProductReviewDoesNotFire(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        // entity row id 2 = customer reviews in stock data
        $observer->execute($this->observerEvent(
            ['object' => $this->review(Review::STATUS_PENDING, Review::STATUS_APPROVED, 2)]
        ));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutProductId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            ['object' => $this->review(Review::STATUS_PENDING, Review::STATUS_APPROVED, 1, 0)]
        ));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutReviewInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([]));

        $this->assertCount(0, $publisher->published);
    }

    public function testIncludesRatingSummaryWhenCheaplyAvailable(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewStatusChangedObserver($publisher, new RecordingLogger());

        $review = new class(21, Review::STATUS_APPROVED, Review::STATUS_PENDING, 1, 55) extends FakeReview {
            public function getData($key = '', $index = null)
            {
                return $key === 'rating_summary' ? 80 : parent::getData($key, $index);
            }
        };

        $observer->execute($this->observerEvent(['object' => $review]));

        $this->assertCount(1, $publisher->published);
        $this->assertSame(80.0, $publisher->published[0]['data']['rating_summary']);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakReviewSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new ReviewStatusChangedObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->observerEvent(
            ['object' => $this->review(Review::STATUS_PENDING, Review::STATUS_APPROVED)]
        ));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('review.status_changed', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
