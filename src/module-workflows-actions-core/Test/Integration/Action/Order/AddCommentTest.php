<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsActionsCore\Action\Order\AddComment;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.add_comment writes a
 * real status-history row, and its execution-UUID + step-key dedupe marker
 * survives redelivery: a second execute() with the SAME context finds the
 * marker and skips, leaving exactly one comment (docs/08 dedupe amendment:
 * UUID + step key).
 *
 * @magentoDbIsolation enabled
 */
class AddCommentTest extends ActionTestCase
{
    private const COMMENT = 'Order reviewed by integration workflow';

    private OrderRepositoryInterface $orderRepository;
    private AddComment $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->action = $this->resolve(AddComment::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testWritesStatusHistoryRow(): void
    {
        $order = $this->fixtureOrder();
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $result = $this->action->execute($ctx, ['comment' => self::COMMENT]);

        $this->assertTrue($result->isSuccess());
        $this->assertGreaterThan(0, (int)$result->getOutput()['comment_id']);
        $this->assertSame(1, $this->countMatchingComments((int)$order->getId()));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testRedeliveryIsDedupedToOneComment(): void
    {
        $order = $this->fixtureOrder();
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $first = $this->action->execute($ctx, ['comment' => self::COMMENT]);
        // Same context = same dedupe key = a redelivered message.
        $second = $this->action->execute($ctx, ['comment' => self::COMMENT]);

        $this->assertTrue($first->isSuccess());
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertSame(
            1,
            $this->countMatchingComments((int)$order->getId()),
            'Redelivery must not add a second comment'
        );
    }

    /**
     * A different step key is a different dedupe marker: two comment steps coexist
     * (docs/19: "the key is UUID + step key ... two comment steps coexist").
     *
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testDistinctStepKeysCoexist(): void
    {
        $order = $this->fixtureOrder();
        $ctxA = $this->buildContext((int)$order->getId(), (int)$order->getStoreId(), 'comment_a');
        $ctxB = $this->buildContext((int)$order->getId(), (int)$order->getStoreId(), 'comment_b');

        $this->assertTrue($this->action->execute($ctxA, ['comment' => self::COMMENT])->isSuccess());
        $this->assertTrue($this->action->execute($ctxB, ['comment' => self::COMMENT])->isSuccess());

        $this->assertSame(2, $this->countMatchingComments((int)$order->getId()));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testMissingCommentIsTerminalFailure(): void
    {
        $order = $this->fixtureOrder();
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $result = $this->action->execute($ctx, []);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }

    private function countMatchingComments(int $orderId): int
    {
        $order = $this->orderRepository->get($orderId);
        $count = 0;
        foreach ($order->getStatusHistories() ?: [] as $history) {
            if (str_contains((string)$history->getComment(), self::COMMENT)) {
                $count++;
            }
        }
        return $count;
    }
}
