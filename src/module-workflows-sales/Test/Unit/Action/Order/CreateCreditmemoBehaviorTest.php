<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\CreateCreditmemo;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for order.create_creditmemo: a regression here refunds
 * money twice (redelivery) or misclassifies failures. Pins the promises in
 * docs/07-actions.md (canCreditmemo() guard, RefundOrderInterface) and
 * docs/08-execution-model.md (at-least-once delivery, retryable flag).
 */
class CreateCreditmemoBehaviorTest extends TestCase
{
    public function testExecuteSkipsWhenOrderCannotBeRefundedAndNeverCallsRefundService(): void
    {
        $order = $this->createFakeOrder(canCreditmemo: false);
        $refund = $this->createRecordingRefund();
        $action = new CreateCreditmemo($this->createRepositoryReturning($order), $refund);

        $result = $action->execute($this->createContext(), []);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $refund->calls, 'A non-refundable order must never reach RefundOrderInterface');
        $this->assertStringContainsString('cannot be refunded', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteRefundsExactlyOnceOnHappyPath(): void
    {
        $order = $this->createFakeOrder(canCreditmemo: true);
        $refund = $this->createRecordingRefund(creditmemoId: 501);
        $action = new CreateCreditmemo($this->createRepositoryReturning($order), $refund);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls, 'Happy path must call RefundOrderInterface exactly once');
        $this->assertSame(42, $refund->lastOrderId);
        $this->assertSame([], $refund->lastItems, 'Empty items = refund everything refundable');
        $this->assertFalse($refund->lastNotify, 'notify must default to false (no surprise refund emails)');
        $this->assertSame(501, $result->getOutput()['creditmemo_id']);
    }

    public function testExecutePassesNotifyConfigThroughToRefundService(): void
    {
        $order = $this->createFakeOrder(canCreditmemo: true);
        $refund = $this->createRecordingRefund(creditmemoId: 502);
        $action = new CreateCreditmemo($this->createRepositoryReturning($order), $refund);

        $result = $action->execute($this->createContext(), ['notify' => 'yes']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($refund->lastNotify);
        $this->assertTrue($result->getOutput()['notify']);
    }

    public function testExecuteLocalizedExceptionIsTerminalFailure(): void
    {
        $order = $this->createFakeOrder(canCreditmemo: true);
        $refund = $this->createRecordingRefund();
        $refund->throwOnExecute = new LocalizedException(new Phrase('Creditmemo Document Validation Error'));
        $action = new CreateCreditmemo($this->createRepositoryReturning($order), $refund);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'A state/config problem must not burn the retry queue');
        $this->assertStringContainsString('Creditmemo Document Validation Error', (string)$result->getError());
    }

    public function testExecuteGenericExceptionIsRetryableFailure(): void
    {
        $order = $this->createFakeOrder(canCreditmemo: true);
        $refund = $this->createRecordingRefund();
        $refund->throwOnExecute = new \RuntimeException('Deadlock found when trying to get lock');
        $action = new CreateCreditmemo($this->createRepositoryReturning($order), $refund);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'Infrastructure flakiness must be redelivered via the queue');
        $this->assertStringContainsString('Deadlock', (string)$result->getError());
    }

    public function testExecuteOrderNotFoundIsTerminalFailure(): void
    {
        $refund = $this->createRecordingRefund();
        $repository = new class implements OrderRepositoryInterface {
            public function get($id)
            {
                throw new NoSuchEntityException();
            }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function save($entity) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($id) { throw new \BadMethodCallException(__METHOD__); }
        };
        $action = new CreateCreditmemo($repository, $refund);

        $result = $action->execute($this->createContext(), []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertSame(0, $refund->calls);
        $this->assertStringContainsString('not found', (string)$result->getError());
    }

    public function testSimulateNeverCallsRefundService(): void
    {
        $order = $this->createFakeOrder(canCreditmemo: true);
        $refund = $this->createRecordingRefund();
        $action = new CreateCreditmemo($this->createRepositoryReturning($order), $refund);

        $result = $action->simulate($this->createContext(), ['notify' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame(0, $refund->calls, 'simulate() must not move money');
    }

    private function createContext(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 42));
    }

    private function createFakeOrder(bool $canCreditmemo): Order
    {
        $order = new class extends Order {
            public bool $canCreditmemoFlag = false;
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
                return Order::STATE_CLOSED;
            }
            public function canCreditmemo(): bool
            {
                return $this->canCreditmemoFlag;
            }
        };
        $order->canCreditmemoFlag = $canCreditmemo;
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

    private function createRecordingRefund(int $creditmemoId = 500)
    {
        return new class($creditmemoId) implements RefundOrderInterface {
            public int $calls = 0;
            public ?int $lastOrderId = null;
            public ?array $lastItems = null;
            public ?bool $lastNotify = null;
            public ?\Throwable $throwOnExecute = null;
            public function __construct(private readonly int $creditmemoId)
            {
            }
            public function execute(
                $orderId,
                array $items = [],
                $notify = false,
                $appendComment = false,
                $comment = null,
                $arguments = null
            ): int {
                $this->calls++;
                $this->lastOrderId = (int)$orderId;
                $this->lastItems = $items;
                $this->lastNotify = (bool)$notify;
                if ($this->throwOnExecute !== null) {
                    throw $this->throwOnExecute;
                }
                return $this->creditmemoId;
            }
        };
    }
}
