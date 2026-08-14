<?php
declare(strict_types=1);

namespace MageOS\WorkflowsReview\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Review\Model\Review;
use MageOS\WorkflowsReview\Model\ReviewPayloadEnricher;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: publishes 'catalog.product.review_submitted' on
 * 'review_save_after' once a product review becomes visible — either
 * created directly in Approved status (reviews by trusted customers /
 * admin) or transitioned Pending -> Approved during moderation. Each
 * review fires at most once: subsequent saves that keep the status
 * approved do not re-publish.
 *
 * The payload hydrates the reviewed product (entity = catalog_product);
 * review context rides along as extra keys. Publishing failures are
 * logged, never allowed to break the review save.
 */
class ReviewSubmittedObserver implements ObserverInterface
{
    public const EVENT_NAME = 'catalog.product.review_submitted';

    public function __construct(
        private readonly EventPublisher $eventPublisher,
        private readonly LoggerInterface $logger,
        private readonly ReviewPayloadEnricher $payloadEnricher
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
        if (!$this->becameApproved($review) || !$this->isProductReview($review)) {
            return;
        }

        $productId = (int) $review->getEntityPkValue();
        if ($productId <= 0) {
            return;
        }

        try {
            $context = $this->payloadEnricher->enrich($review);
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'productId' hydrates via ProductRepositoryInterface::getById($productId)
                'productId' => $productId,
                'entity_id' => $productId,
                'review_id' => (int) $review->getId(),
                'review_title' => (string) $review->getTitle(),
                'review_nickname' => (string) $review->getNickname(),
                'store_id' => (int) $review->getStoreId(),
                // Enriched context (each null when unavailable): average star
                // rating across the review's rating dimensions, and the
                // reviewer's identity for logged-in reviewers. Guest reviews
                // carry null customer fields.
                'rating' => $context['rating'],
                'customer_id' => $context['customer_id'],
                'customer_email' => $context['customer_email'],
            ]);
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

    private function becameApproved(Review $review): bool
    {
        if ((int) $review->getStatusId() !== Review::STATUS_APPROVED) {
            return false;
        }
        $originalStatus = $review->getOrigData('status_id');

        // Newly approved: created approved, or moderated pending -> approved.
        return $originalStatus === null || (int) $originalStatus !== Review::STATUS_APPROVED;
    }

    private function isProductReview(Review $review): bool
    {
        $entityId = $review->getEntityId();

        // Review::ENTITY_PRODUCT_CODE row id is 1 in stock data; prefer a
        // lookup-free check on the already-loaded entity id.
        return $entityId === null || (int) $entityId === 1;
    }
}
