<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\WorkflowsSales\Action\Order\CreateInvoice;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #18 (docs/20-integration-test-plan.md §5) — order.create_invoice
 * creates a real invoice with the correct total, and a redelivery is SKIPPED
 * once the order is fully invoiced (canInvoice() false => skipped).
 *
 * @magentoDbIsolation enabled
 */
class InvoiceTest extends ActionTestCase
{
    private OrderRepositoryInterface $orderRepository;
    private CreateInvoice $action;

    protected function setUp(): void
    {
        $this->orderRepository = $this->resolve(OrderRepositoryInterface::class);
        $this->action = $this->resolve(CreateInvoice::class);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testCreatesInvoiceWithCorrectTotalThenSkips(): void
    {
        $order = $this->fixtureOrder();
        if (!$order->canInvoice()) {
            $this->markTestSkipped('Fixture order cannot be invoiced in this install');
        }
        $expectedTotal = (float)$order->getGrandTotal();
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());

        $first = $this->action->execute($ctx, ['capture' => 'offline']);
        $this->assertTrue($first->isSuccess(), $first->getError() ?? '');
        $output = $first->getOutput();
        $this->assertGreaterThan(0, (int)$output['invoice_id']);
        $this->assertEqualsWithDelta($expectedTotal, (float)$output['grand_total'], 0.001);
        $this->assertSame('offline', $output['capture']);

        $reloaded = $this->orderRepository->get((int)$order->getId());
        $this->assertSame(1, $reloaded->getInvoiceCollection()->getSize(), 'Exactly one invoice row must exist');
        $this->assertGreaterThan(0.0, (float)$reloaded->getTotalPaid());

        // Fully invoiced => redelivery skips.
        $second = $this->action->execute($ctx, ['capture' => 'offline']);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $second->getStatus());
        $this->assertSame(1, $this->orderRepository->get((int)$order->getId())->getInvoiceCollection()->getSize());
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order.php
     */
    public function testInvalidCaptureModeIsTerminalFailure(): void
    {
        $order = $this->fixtureOrder();
        $ctx = $this->buildContext((int)$order->getId(), (int)$order->getStoreId());
        $result = $this->action->execute($ctx, ['capture' => 'bogus']);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    private function fixtureOrder(): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $this->assertNotEmpty($order->getId());
        return $order;
    }
}
