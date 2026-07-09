<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order\Status\History;
use MageOS\WorkflowsSales\Observer\OrderCommentAddedObserver;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingEventPublisher;
use MageOS\WorkflowsTriggersCore\Test\Unit\Stub\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Pins the comment-added contract (ORD-T2): 'sales.order.comment_added' is
 * published hydrating the parent order (entity=sales_order) with the comment
 * text, status and notified/visible flags as payload extras — ONLY for rows
 * that carry real comment text (a status-only row is left to
 * sales.order.status_changed). No parent id -> no publish; a publish failure is
 * logged and never breaks the save.
 */
class OrderCommentAddedObserverTest extends TestCase
{
    private function history(
        int $parentId,
        string $comment,
        string $status = 'processing',
        bool $notified = true,
        bool $visible = true,
        int $entityId = 7
    ): History {
        return new class($parentId, $comment, $status, $notified, $visible, $entityId) extends History {
            public function __construct(
                private readonly int $parentId,
                private readonly string $comment,
                private readonly string $status,
                private readonly bool $notified,
                private readonly bool $visible,
                private readonly int $entityId
            ) {
            }
            public function getParentId()
            {
                return $this->parentId;
            }
            public function getComment()
            {
                return $this->comment;
            }
            public function getStatus()
            {
                return $this->status;
            }
            public function getIsCustomerNotified()
            {
                return $this->notified;
            }
            public function getIsVisibleOnFront()
            {
                return $this->visible;
            }
            public function getEntityId()
            {
                return $this->entityId;
            }
        };
    }

    private function event(mixed $history): Observer
    {
        return new Observer(['event' => new Event($history === null ? [] : ['data_object' => $history])]);
    }

    public function testPublishesCommentAddedHydratingTheParentOrder(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderCommentAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->history(
            parentId: 55,
            comment: 'Packed and ready',
            status: 'processing',
            notified: true,
            visible: false,
            entityId: 9
        )));

        $this->assertCount(1, $publisher->published);
        $data = $publisher->published[0]['data'];
        $this->assertSame('sales.order.comment_added', $publisher->published[0]['event']);
        $this->assertSame(55, $data['id']);
        $this->assertSame(55, $data['entity_id']);
        $this->assertSame(55, $data['order_id']);
        $this->assertSame(9, $data['comment_id']);
        $this->assertSame('Packed and ready', $data['comment']);
        $this->assertSame('processing', $data['status']);
        $this->assertTrue($data['is_customer_notified']);
        $this->assertFalse($data['is_visible_on_front']);
    }

    public function testDoesNotFireForStatusOnlyRowWithoutComment(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderCommentAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->history(parentId: 55, comment: '   ')));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutParentOrderId(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderCommentAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event($this->history(parentId: 0, comment: 'orphan comment')));

        $this->assertCount(0, $publisher->published);
    }

    public function testDoesNotFireWithoutHistoryInEvent(): void
    {
        $publisher = new RecordingEventPublisher();
        $observer = new OrderCommentAddedObserver($publisher, new RecordingLogger());

        $observer->execute($this->event(null));

        $this->assertCount(0, $publisher->published);
    }

    public function testPublishFailureIsLoggedAndDoesNotBreakSave(): void
    {
        $publisher = new RecordingEventPublisher(new \RuntimeException('amqp connection refused'));
        $logger = new RecordingLogger();
        $observer = new OrderCommentAddedObserver($publisher, $logger);

        // Must not throw.
        $observer->execute($this->event($this->history(parentId: 55, comment: 'Packed')));

        $this->assertStringContainsString('error:', $logger->allMessages());
        $this->assertStringContainsString('sales.order.comment_added', $logger->allMessages());
        $this->assertStringContainsString('amqp connection refused', $logger->allMessages());
    }
}
