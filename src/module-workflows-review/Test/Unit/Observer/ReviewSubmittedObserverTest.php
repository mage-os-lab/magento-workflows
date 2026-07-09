<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Review\Model\Review;
use MageOS\WorkflowsReview\Observer\ReviewSubmittedObserver;
use MageOS\WorkflowsReview\Test\Unit\Stub\FakeReview;
use MageOS\WorkflowsReview\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsReview\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the at-most-once visibility contract: a product review fires
 * 'catalog.product.review_submitted' exactly when it BECOMES approved —
 * created directly approved, or moderated pending -> approved. Re-saving an
 * already-approved review must not re-publish, pending reviews never fire,
 * and non-product reviews are excluded. Publish failures are logged, never
 * rethrown into the review save.
 */
class ReviewSubmittedObserverTest extends TestCase
{
    /**
     * @param array $eventData
     */
    private function observerEvent(array $eventData): Observer
    {
        return new Observer(['event' => new Event($eventData)]);
    }

    private function approvedProductReview(?int $origStatus, $entityId = 1): FakeReview
    {
        return new FakeReview(
            21,
            Review::STATUS_APPROVED,
            $origStatus,
            $entityId,
            55,
            'Great product',
            'Jane',
            2
        );
    }

    public function testFiresOnceWhenReviewCreatedDirectlyApproved(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['object' => $this->approvedProductReview(null)]));

        $this->assertCount(1, $publisher->published);
        $this->assertSame('catalog.product.review_submitted', $publisher->published[0]['event']);
        $this->assertSame(55, $publisher->published[0]['data']['productId']);
        $this->assertSame(55, $publisher->published[0]['data']['entity_id']);
        $this->assertSame(21, $publisher->published[0]['data']['review_id']);
        $this->assertSame('Great product', $publisher->published[0]['data']['review_title']);
        $this->assertSame('Jane', $publisher->published[0]['data']['review_nickname']);
        $this->assertSame(2, $publisher->published[0]['data']['store_id']);
    }

    public function testFiresOnPendingToApprovedModeration(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            ['data_object' => $this->approvedProductReview(Review::STATUS_PENDING)]
        ));

        $this->assertCount(1, $publisher->published);
    }

    public function testDoesNotRefireOnSubsequentSavesOfApprovedReview(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(
            ['object' => $this->approvedProductReview(Review::STATUS_APPROVED)]
        ));

        $this->assertCount(0, $publisher->published, 'each review must fire at most once');
    }

    public function testDoesNotFireWhilePending(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $review = new FakeReview(21, Review::STATUS_PENDING, null, 1, 55);
        $observer->execute($this->observerEvent(['object' => $review]));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireForNonProductReview(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        // entity row id 2 = customer reviews in stock data
        $observer->execute($this->observerEvent(['object' => $this->approvedProductReview(null, 2)]));

        $this->assertCount(0, $publisher->published);
    }

    public function testFiresWhenEntityTypeUnknownLookupFreeGrace(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent(['object' => $this->approvedProductReview(null, null)]));

        $this->assertCount(1, $publisher->published);
    }

    public function testDoesNotFireWithoutProductId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $review = new FakeReview(21, Review::STATUS_APPROVED, null, 1, 0);
        $observer->execute($this->observerEvent(['object' => $review]));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutReviewInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new ReviewSubmittedObserver($publisher, new RecordingLogger());

        $observer->execute($this->observerEvent([]));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakReviewSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new ReviewSubmittedObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->observerEvent(['object' => $this->approvedProductReview(null)]));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('catalog.product.review_submitted', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
