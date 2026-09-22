<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\CreateShipment;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for order.create_shipment: a regression here ships
 * goods twice under queue redelivery. Pins the promises in docs/07-actions.md
 * (canShip() guard, ShipOrderInterface) and docs/08-execution-model.md
 * (at-least-once delivery, retryable flag).
 */
class CreateShipmentBehaviorTest extends TestCase
{
    public function testExecuteSkipsWhenOrderCannotBeShippedAndNeverCallsShipService(): void
    {
        $order = $this->createFakeOrder(canShip: false);
        $shipOrder = $this->createRecordingShipOrder();
        $action = new CreateShipment($this->createRepositoryReturning($order), $shipOrder);

        $result = $action->execute($this->createContext(), []);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $shipOrder->calls, 'An unshippable order must never reach ShipOrderInterface');
        $this->assertStringContainsString('cannot be shipped', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteShipsExactlyOnceOnHappyPath(): void
    {
        $order = $this->createFakeOrder(canShip: true);
        $shipOrder = $this->createRecordingShipOrder(shipmentId: 701);
        $action = new CreateShipment($this->createRepositoryReturning($order), $shipOrder);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $shipOrder->calls, 'Happy path must call ShipOrderInterface exactly once');
        $this->assertSame(42, $shipOrder->lastOrderId);
        $this->assertSame([], $shipOrder->lastItems, 'Empty items = ship everything shippable');
        $this->assertFalse($shipOrder->lastNotify, 'notify must default to false');
        $this->assertSame(701, $result->getOutput()['shipment_id']);
    }

    public function testExecutePassesNotifyConfigThroughToShipService(): void
    {
        $order = $this->createFakeOrder(canShip: true);
        $shipOrder = $this->createRecordingShipOrder(shipmentId: 702);
        $action = new CreateShipment($this->createRepositoryReturning($order), $shipOrder);

        $result = $action->execute($this->createContext(), ['notify' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($shipOrder->lastNotify);
        $this->assertTrue($result->getOutput()['notify']);
    }

    public function testExecuteLocalizedExceptionIsTerminalFailure(): void
    {
        $order = $this->createFakeOrder(canShip: true);
        $shipOrder = $this->createRecordingShipOrder();
        $shipOrder->throwOnExecute = new LocalizedException(new Phrase('Shipment Document Validation Error'));
        $action = new CreateShipment($this->createRepositoryReturning($order), $shipOrder);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'A state/config problem must not burn the retry queue');
        $this->assertStringContainsString('Shipment Document Validation Error', (string)$result->getError());
    }

    public function testExecuteGenericExceptionIsRetryableFailure(): void
    {
        $order = $this->createFakeOrder(canShip: true);
        $shipOrder = $this->createRecordingShipOrder();
        $shipOrder->throwOnExecute = new \RuntimeException('MySQL server has gone away');
        $action = new CreateShipment($this->createRepositoryReturning($order), $shipOrder);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'Infrastructure flakiness must be redelivered via the queue');
        $this->assertStringContainsString('gone away', (string)$result->getError());
    }

    public function testSimulateNeverCallsShipService(): void
    {
        $order = $this->createFakeOrder(canShip: true);
        $shipOrder = $this->createRecordingShipOrder();
        $action = new CreateShipment($this->createRepositoryReturning($order), $shipOrder);

        $result = $action->simulate($this->createContext(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame(0, $shipOrder->calls, 'simulate() must not ship goods');
    }

    private function createContext(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 42));
    }

    private function createFakeOrder(bool $canShip): Order
    {
        $order = new class extends Order {
            public bool $canShipFlag = false;
            public function __construct()
            {
            }
            public function getEntityId(): int
            {
                return 42;
            }
            public function getIncrementId(): string
            {
                return '100000042';
            }
            public function getState(): string
            {
                return Order::STATE_COMPLETE;
            }
            public function canShip(): bool
            {
                return $this->canShipFlag;
            }
        };
        $order->canShipFlag = $canShip;
        return $order;
    }

    private function createRepositoryReturning(Order $order): OrderRepositoryInterface
    {
        return new class($order) implements OrderRepositoryInterface {
            public function __construct(private readonly Order $order)
            {
            }
            public function get($id)
            {
                return $this->order;
            }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    private function createRecordingShipOrder(int $shipmentId = 700)
    {
        return new class($shipmentId) implements ShipOrderInterface {
            public int $calls = 0;
            public ?int $lastOrderId = null;
            public ?array $lastItems = null;
            public ?bool $lastNotify = null;
            public ?\Throwable $throwOnExecute = null;
            public function __construct(private readonly int $shipmentId)
            {
            }
            public function execute(
                $orderId,
                array $items = [],
                $notify = false,
                $appendComment = false,
                $comment = null,
                array $tracks = [],
                array $packages = [],
                $arguments = null
            ): int {
                $this->calls++;
                $this->lastOrderId = (int)$orderId;
                $this->lastItems = $items;
                $this->lastNotify = (bool)$notify;
                if ($this->throwOnExecute !== null) {
                    throw $this->throwOnExecute;
                }
                return $this->shipmentId;
            }
        };
    }
}
