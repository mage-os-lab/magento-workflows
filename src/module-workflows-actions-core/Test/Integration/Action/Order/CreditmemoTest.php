<?php
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
 * row, and a redelivery is SKIPPED.
 *
 * Two guards make that skip happen against a real database, and this test sees
 * the first one: the dedupe MARKER the action writes onto the memo's own
 * comment (execution UUID + step key — both stable across the two calls here)
 * is found by the second delivery before any refund is attempted. The
 * canCreditmemo() state guard behind it would also skip this particular
 * full-mode case, but it does not hold for the partial modes — see
 * CreateCreditmemoDedupeTest.
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
        $this->assertStringContainsString(
            'already refunded by this step',
            (string)($second->getOutput()['reason'] ?? ''),
            'The dedupe marker, not the state guard, must be what stops the redelivery'
        );
        $this->assertSame(
            (int)$first->getOutput()['creditmemo_id'],
            (int)($second->getOutput()['creditmemo_id'] ?? 0),
            'The skip must hand back the memo the first delivery created'
        );
        $this->assertSame(1, $this->orderRepository->get((int)$order->getId())->getCreditmemosCollection()->getSize());
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }
}
