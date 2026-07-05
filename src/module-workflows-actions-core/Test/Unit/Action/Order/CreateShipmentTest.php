<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Order;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\Order\CreateShipment;
use PHPUnit\Framework\TestCase;

class CreateShipmentTest extends TestCase
{
    public function testBoolConfigWithNotifyFalseDefaults(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $shipOrder = $this->createShipOrderStub();

        $action = new CreateShipment($orderRepo, $shipOrder);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');
        $method->setAccessible(true);

        $result = $method->invoke($action, ['notify' => false], 'notify');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithNotifyTrue(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $shipOrder = $this->createShipOrderStub();

        $action = new CreateShipment($orderRepo, $shipOrder);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');
        $method->setAccessible(true);

        $result = $method->invoke($action, ['notify' => true], 'notify');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithNotifyString1(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $shipOrder = $this->createShipOrderStub();

        $action = new CreateShipment($orderRepo, $shipOrder);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');
        $method->setAccessible(true);

        $result = $method->invoke($action, ['notify' => '1'], 'notify');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithNotifyStringYes(): void
    {
        $orderRepo = $this->createOrderRepositoryStub();
        $shipOrder = $this->createShipOrderStub();

        $action = new CreateShipment($orderRepo, $shipOrder);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $reflection = new \ReflectionClass($action);
        $method = $reflection->getMethod('boolConfig');
        $method->setAccessible(true);

        $result = $method->invoke($action, ['notify' => 'yes'], 'notify');
        $this->assertTrue($result);
    }

    private function createOrderRepositoryStub(): OrderRepositoryInterface
    {
        return new class implements OrderRepositoryInterface {
            public function get($orderId) { throw new \RuntimeException('Should not be called'); }
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete(\Magento\Sales\Api\Data\OrderInterface $entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save(\Magento\Sales\Api\Data\OrderInterface $entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    private function createShipOrderStub(): ShipOrderInterface
    {
        return new class implements ShipOrderInterface {
            public function execute($orderId, array $items = [], $notify = false, $appendComment = false, ?\Magento\Sales\Api\Data\ShipmentCommentCreationInterface $comment = null, array $tracks = [], array $packages = [], ?\Magento\Sales\Api\Data\ShipmentCreationArgumentsInterface $arguments = null): int { throw new \RuntimeException('Should not be called'); }
        };
    }
}
