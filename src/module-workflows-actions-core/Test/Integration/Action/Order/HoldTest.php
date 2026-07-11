<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsSales\Action\Order\Hold;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.hold holds an order,
 * then a redelivery is SKIPPED because canHold() is false once held (canX
 * false => skipped, never failed).
 *
 * @magentoDbIsolation enabled
 */
class HoldTest extends ActionTestCase
{
    private OrderRepositoryInterface $orderRepository;
    private Hold $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->action = $this->resolve(Hold::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testHoldsThenSkipsOnRedelivery(): void
    {
        $order = $this->fixtureOrder();
        if (!$order->canHold()) {
            $this->markTestSkipped('Fixture order cannot be held in this install');
        }
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $first = $this->action->execute($ctx, []);
        $this->assertTrue($first->isSuccess());
        $this->assertSame(
            Order::STATE_HOLDED,
            $this->orderRepository->get((int)$order->getId())->getState()
        );

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
