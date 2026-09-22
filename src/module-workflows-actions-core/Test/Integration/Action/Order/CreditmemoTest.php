<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsSales\Action\Order\CreateCreditmemo;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.create_creditmemo
 * offline-refunds a paid order (invoice fixture), creating a real credit memo
 * row, and a redelivery is SKIPPED once fully refunded (canCreditmemo() false
 * => skipped).
 *
 * @magentoDbIsolation enabled
 */
class CreditmemoTest extends ActionTestCase
{
    private OrderRepositoryInterface $orderRepository;
    private CreateCreditmemo $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->action = $this->resolve(CreateCreditmemo::class);
    }

    /**
     * @magentoDataFixture MageOS_WorkflowsActionsCore::Test/Integration/_files/paid_order_for_creditmemo.php
     */
    public function testCreatesCreditmemoThenSkips(): void
    {
        $order = $this->fixtureOrder();
        if (!$order->canCreditmemo()) {
            $this->markTestSkipped('Fixture order cannot be refunded in this install');
        }
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $first = $this->action->execute($ctx, []);
        $this->assertTrue($first->isSuccess(), $first->getError() ?? '');
        $this->assertGreaterThan(0, (int)$first->getOutput()['creditmemo_id']);

        $reloaded = $this->orderRepository->get((int)$order->getId());
        $this->assertSame(1, $reloaded->getCreditmemosCollection()->getSize(), 'Exactly one credit memo row must exist');
        $this->assertGreaterThan(0.0, (float)$reloaded->getTotalRefunded());

        $second = $this->action->execute($ctx, []);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertSame(1, $this->orderRepository->get((int)$order->getId())->getCreditmemosCollection()->getSize());
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }
}
