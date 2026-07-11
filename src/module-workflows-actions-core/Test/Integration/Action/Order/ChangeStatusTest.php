<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsSales\Action\Order\ChangeStatus;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.change_status
 * respects the real state machine: a status assigned to the order's current
 * state applies (legal transition), a status NOT assigned to it is a terminal
 * step failure (docs/07:48 "an invalid transition = step failure", which is
 * NOT on the docs/19 docs-stale list and matches the implementation), and a
 * no-op (already at the target status) is skipped.
 *
 * @magentoDbIsolation enabled
 */
class ChangeStatusTest extends ActionTestCase
{
    private OrderRepositoryInterface $orderRepository;
    private OrderConfig $orderConfig;
    private ChangeStatus $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->orderConfig = $this->resolve(OrderConfig::class);
        $this->action = $this->resolve(ChangeStatus::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testLegalInStateTransitionApplies(): void
    {
        $order = $this->fixtureOrder();
        $current = (string)$order->getStatus();
        $target = $this->pickOtherStatusInState((string)$order->getState(), $current);
        if ($target === null) {
            $this->markTestSkipped('No second status assigned to state "' . $order->getState() . '" in this install');
        }

        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());
        $result = $this->action->execute($ctx, ['status' => $target]);

        $this->assertTrue($result->isSuccess(), 'A legal in-state transition must succeed');
        $this->assertSame($current, $result->getOutput()['previous_status']);
        $this->assertSame($target, $this->orderRepository->get((int)$order->getId())->getStatus());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testIllegalTransitionIsTerminalFailure(): void
    {
        $order = $this->fixtureOrder();
        $illegal = $this->pickStatusOutsideState((string)$order->getState());
        if ($illegal === null) {
            $this->markTestSkipped('Could not find a status outside the order state to test the illegal path');
        }

        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());
        $result = $this->action->execute($ctx, ['status' => $illegal]);

        $this->assertTrue($result->isFailure(), 'An out-of-state status must fail');
        $this->assertFalse($result->isRetryable(), 'An invalid transition is terminal, not retryable');
        // Order status is unchanged.
        $this->assertSame(
            (string)$order->getStatus(),
            $this->orderRepository->get((int)$order->getId())->getStatus()
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testNoOpToSameStatusIsSkipped(): void
    {
        $order = $this->fixtureOrder();
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $result = $this->action->execute($ctx, ['status' => (string)$order->getStatus()]);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }

    private function pickOtherStatusInState(string $state, string $current): ?string
    {
        foreach (array_keys($this->orderConfig->getStateStatuses($state)) as $status) {
            if ((string)$status !== $current) {
                return (string)$status;
            }
        }
        return null;
    }

    private function pickStatusOutsideState(string $state): ?string
    {
        $inState = $this->orderConfig->getStateStatuses($state);
        foreach (array_keys($this->orderConfig->getStatuses()) as $status) {
            if (!array_key_exists($status, $inState)) {
                return (string)$status;
            }
        }
        return null;
    }
}
