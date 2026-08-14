<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsSales\Action\Order\CreateCreditmemo;
use PHPUnit\Framework\TestCase;

class CreateCreditmemoTest extends TestCase
{
    use CreditmemoRefundDoubles;

    public function testBoolConfigWithNotifyFalseDefaults(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $refundOrder = $this->createRefundOrderStub();

        $action = new CreateCreditmemo($orderRepo, $refundOrder, ...$this->markerDoubles());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');

        $result = $method->invoke($action, ['notify' => false], 'notify');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithNotifyTrue(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $refundOrder = $this->createRefundOrderStub();

        $action = new CreateCreditmemo($orderRepo, $refundOrder, ...$this->markerDoubles());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');

        $result = $method->invoke($action, ['notify' => true], 'notify');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithNotifyString1(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $refundOrder = $this->createRefundOrderStub();

        $action = new CreateCreditmemo($orderRepo, $refundOrder, ...$this->markerDoubles());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');

        $result = $method->invoke($action, ['notify' => '1'], 'notify');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithNotifyStringYes(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $refundOrder = $this->createRefundOrderStub();

        $action = new CreateCreditmemo($orderRepo, $refundOrder, ...$this->markerDoubles());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');

        $result = $method->invoke($action, ['notify' => 'yes'], 'notify');
        $this->assertTrue($result);
    }

    /**
     * The three redelivery-marker dependencies, in constructor order. None of
     * them is reached by these config-helper tests.
     *
     * @return array{0: \Magento\Sales\Api\CreditmemoRepositoryInterface, 1: \Magento\Framework\Api\SearchCriteriaBuilder, 2: \Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory}
     */
    private function markerDoubles(): array
    {
        return [
            $this->creditmemoRepositoryWith(),
            $this->creditmemoCriteriaBuilder(),
            $this->creditmemoCommentFactory(),
        ];
    }

    private function createOrderRepositoryStub(): OrderRepositoryInterface
    {
        return new class implements OrderRepositoryInterface {
            public function get($id) { throw new \RuntimeException('Should not be called'); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    private function createRefundOrderStub(): RefundOrderInterface
    {
        return new class implements RefundOrderInterface {
            public function execute($orderId, array $items = [], $notify = false, $appendComment = false, $comment = null, $arguments = null): int { throw new \RuntimeException('Should not be called'); }
        };
    }
}
