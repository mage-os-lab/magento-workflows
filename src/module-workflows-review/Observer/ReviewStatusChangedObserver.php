<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Review\Model\Review;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * REV-T1 — publishes 'review.status_changed' on 'review_save_after' whenever a
 * product review's moderation status TRANSITIONS (orig status_id != new
 * status_id). Sibling of ReviewSubmittedObserver: both ride the same
 * review_save_after seam but cover disjoint moments —
 *
 *   - ReviewSubmittedObserver fires the ONE-TIME "became visible" event
 *     (catalog.product.review_submitted) when a review first reaches Approved.
 *   - THIS observer fires the general moderation transition
 *     (review.status_changed) with from/to on EVERY status change of an
 *     EXISTING review.
 *
 * Boundary with review_submitted (documented, pinned by tests): a freshly
 * created review has NO original status (getOrigData('status_id') === null).
 * A brand-new review is therefore NOT a "status change" — it is a submission,
 * owned by ReviewSubmittedObserver. This observer only fires when an original
 * status exists AND differs from the new one, so "new review created directly
 * Approved" fires review_submitted, never review.status_changed.
 *
 * The payload hydrates the reviewed product (entity = catalog_product, async
 * service ProductRepositoryInterface::getById on 'productId'), matching
 * review_submitted so conditions run against the reviewed product; the review
 * context (from/to status ids + labels, rating summary when cheaply available,
 * review_id/title/nickname) rides along as extra message keys.
 *
 * LOOP GUARD (REV-A1 interaction): REV-A1's review.set_status action mutates a
 * review's status_id and saves it, which re-enters review_save_after and can
 * re-publish review.status_changed — the auto-moderation loop "status change ->
 * workflow -> set_status -> status change". Two things bound it: (1) REV-A1 is
 * idempotent — setting the status it already has performs NO save, so no
 * re-publish (see SetStatus); (2) genuine A->B->A oscillations are capped by the
 * engine's chain-depth guard (WorkflowExecution::getChainDepth), the same guard
 * that bounds every self-retriggering workflow. Authors composing set_status
 * onto review.status_changed must gate on the target status to avoid churn.
 *
 * Publishing failures are logged, never allowed to break the review save.
 */
class ReviewStatusChangedObserver implements ObserverInterface
{
    public const EVENT_NAME = 'review.status_changed';

    /**
     * Review status_id => stable label (Magento core moderation states).
     */
    private const STATUS_LABELS = [
        Review::STATUS_APPROVED => 'Approved',
        Review::STATUS_PENDING => 'Pending',
        Review::STATUS_NOT_APPROVED => 'Not Approved',
    ];

    public function __construct(
        private readonly EventPublisher $eventPublisher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $review = $observer->getEvent()->getData('object')
            ?? $observer->getEvent()->getData('data_object');
        if (!$review instanceof Review || !$review->getId()) {
            return;
        }
        if (!$this->isProductReview($review)) {
            return;
        }

        $originalStatus = $review->getOrigData('status_id');
        // Freshly created review: no original status -> a submission, not a
        // transition. Owned by ReviewSubmittedObserver (see class docblock).
        if ($originalStatus === null) {
            return;
        }

        $fromStatus = (int) $originalStatus;
        $toStatus = (int) $review->getStatusId();
        if ($fromStatus === $toStatus) {
            // No moderation change on this save.
            return;
        }

        $productId = (int) $review->getEntityPkValue();
        if ($productId <= 0) {
            return;
        }

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, $this->payload(
                $review,
                $productId,
                $fromStatus,
                $toStatus
            ));
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for review #%d: %s',
                    self::EVENT_NAME,
                    (int) $review->getId(),
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Review $review, int $productId, int $fromStatus, int $toStatus): array
    {
        $payload = [
            // 'productId' hydrates via ProductRepositoryInterface::getById($productId)
            'productId' => $productId,
            'entity_id' => $productId,
            'review_id' => (int) $review->getId(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_status_label' => self::STATUS_LABELS[$fromStatus] ?? (string) $fromStatus,
            'to_status_label' => self::STATUS_LABELS[$toStatus] ?? (string) $toStatus,
            'review_title' => (string) $review->getTitle(),
            'review_nickname' => (string) $review->getNickname(),
            'store_id' => (int) $review->getStoreId(),
        ];

        // Rating summary only when cheaply available on the in-memory review
        // object (0-100 percent). Reviews carry it when the aggregate rode along
        // on the save; we never issue a query for it here — absent is fine.
        $ratingSummary = $review->getData('rating_summary');
        if ($ratingSummary !== null && $ratingSummary !== '') {
            $payload['rating_summary'] = (float) $ratingSummary;
        }

        return $payload;
    }

    private function isProductReview(Review $review): bool
    {
        $entityId = $review->getEntityId();

        // Review::ENTITY_PRODUCT_CODE row id is 1 in stock data; prefer a
        // lookup-free check on the already-loaded entity id (null = unknown,
        // graced through — consistent with ReviewSubmittedObserver).
        return $entityId === null || (int) $entityId === 1;
    }
}
