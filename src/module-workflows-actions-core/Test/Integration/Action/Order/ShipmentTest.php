<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsActionsCore\Action\Order\CreateShipment;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.create_shipment
 * creates a real shipment for shippable items, and a redelivery is SKIPPED
 * once fully shipped (canShip() false => skipped, not double-shipped).
 *
 * @magentoDbIsolation enabled
 */
class ShipmentTest extends ActionTestCase
{
    private OrderRepositoryInterface $orderRepository;
    private CreateShipment $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->action = $this->resolve(CreateShipment::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCreatesShipmentThenSkips(): void
    {
        $order = $this->fixtureOrder();
        if (!$order->canShip()) {
            $this->markTestSkipped('Fixture order cannot be shipped in this install');
        }
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $first = $this->action->execute($ctx, []);
        $this->assertTrue($first->isSuccess(), $first->getError() ?? '');
        $this->assertGreaterThan(0, (int)$first->getOutput()['shipment_id']);
        $this->assertSame(
            1,
            $this->orderRepository->get((int)$order->getId())->getShipmentsCollection()->getSize()
        );

        $second = $this->action->execute($ctx, []);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertSame(
            1,
            $this->orderRepository->get((int)$order->getId())->getShipmentsCollection()->getSize()
        );
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }
}
