<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Status\History;
use MageOS\WorkflowsTriggersCore\Service\EventPublisher;
use Psr\Log\LoggerInterface;

/**
 * Gap-fill publisher: detects a comment being added to an order and publishes
 * the 'sales.order.comment_added' async event (declared in etc/async_events.xml),
 * hydrating the PARENT order (entity=sales_order, mirroring every other sales
 * document trigger).
 *
 * Seam: Magento\Sales\Model\Order\Status\History is an AbstractModel; saving it
 * dispatches 'sales_order_status_history_save_after' with the history model on
 * the event's always-present 'data_object' key (read via data_object rather than
 * a version-specific _eventObject alias). Order comments ARE status-history rows,
 * so this fires for order.add_comment (ORD-A1), OrderManagementInterface::addComment,
 * admin "Submit Comment", and any code that persists a history model.
 *
 * Comment-vs-status-row decision (documented honestly): a status change also
 * writes a history row, but with an EMPTY comment. This observer fires ONLY when
 * the row carries actual comment text (trim(getComment()) !== '') — a pure
 * status transition is already covered by 'sales.order.status_changed' (ORD-T1's
 * sibling), so re-publishing it here as a "comment added" would be a lie and a
 * duplicate. "Comment added" means a human/automation wrote words.
 *
 * Loop-guard interaction (documented): the order.add_comment ACTION creates a
 * history row, so an action → trigger → workflow → action chain is possible
 * (order.add_comment fires sales.order.comment_added, which could run a workflow
 * that adds another comment). This is a REAL loop pair. It does NOT runaway: the
 * engine's chain-depth guard bounds trigger→action→trigger recursion, and
 * order.add_comment is itself idempotent per execution (its dedupe marker skips a
 * re-add on redelivery). Author comment-driven workflows with that depth budget
 * in mind. Publishing failures are logged and never break the comment/order save.
 */
class OrderCommentAddedObserver implements ObserverInterface
{
    public const EVENT_NAME = 'sales.order.comment_added';

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
        $history = $observer->getEvent()->getData('data_object');
        if (!$history instanceof History) {
            return;
        }

        $comment = trim((string)$history->getComment());
        if ($comment === '') {
            // Status-only history row: covered by sales.order.status_changed.
            return;
        }

        $orderId = (int)$history->getParentId();
        if ($orderId <= 0) {
            return;
        }

        try {
            $this->eventPublisher->publish(self::EVENT_NAME, [
                // 'id' hydrates the parent order via OrderRepositoryInterface::get($id)
                'id' => $orderId,
                'entity_id' => $orderId,
                'order_id' => $orderId,
                'comment_id' => (int)$history->getEntityId(),
                'comment' => $comment,
                'status' => (string)$history->getStatus(),
                'is_customer_notified' => (bool)$history->getIsCustomerNotified(),
                'is_visible_on_front' => (bool)$history->getIsVisibleOnFront(),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf(
                    'Failed to publish %s for order %d: %s',
                    self::EVENT_NAME,
                    $orderId,
                    $exception->getMessage()
                ),
                ['exception' => $exception]
            );
        }
    }
}
