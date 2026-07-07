<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsActionsCore\Action\Order\Cancel;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.cancel cancels a
 * cancellable order and, once canceled, canX() is false so a redelivery is
 * SKIPPED (not failed) per docs/07 as amended in docs/19: at-least-once
 * safety returns skipped for "already done".
 *
 * @magentoDbIsolation enabled
 */
class CancelTest extends ActionTestCase
{
    private OrderRepositoryInterface $orderRepository;
    private Cancel $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->action = $this->resolve(Cancel::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCancelsThenSkipsOnRedelivery(): void
    {
        $order = $this->fixtureOrder();
        if (!$order->canCancel()) {
            $this->markTestSkipped('Fixture order is not cancellable in this install');
        }
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $first = $this->action->execute($ctx, []);
        $this->assertTrue($first->isSuccess());
        $this->assertSame(
            Order::STATE_CANCELED,
            $this->orderRepository->get((int)$order->getId())->getState()
        );

        // canCancel() is now false — redelivery skips, never fails.
        $second = $this->action->execute($ctx, []);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }
}
