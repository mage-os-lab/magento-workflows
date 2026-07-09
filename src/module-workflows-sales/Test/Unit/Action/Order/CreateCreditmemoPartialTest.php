<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\CreateCreditmemo;
use PHPUnit\Framework\TestCase;

/**
 * ORD-A3: partial credit-memo modes on order.create_creditmemo. percent/fixed
 * refund an ADJUSTMENT amount with no item lines, computed in-action from static
 * config; full mode is unchanged and covered by CreateCreditmemoBehaviorTest.
 */
class CreateCreditmemoPartialTest extends TestCase
{
    public function testPercentModeRefundsPercentOfPaidTotalAsAdjustment(): void
    {
        $refund = $this->recordingRefund(creditmemoId: 900);
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 200.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'percent', 'percent' => '25']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls);
        $this->assertSame([], $refund->lastItems, 'adjustment refund carries no item lines');
        $this->assertNotNull($refund->lastArguments);
        $this->assertSame(50.0, $refund->lastArguments->getAdjustmentPositive());
        $this->assertSame(50.0, $result->getOutput()['refund_amount']);
        $this->assertSame('percent', $result->getOutput()['mode']);
    }

    public function testPercentModeRoundsHalfUpToTwoDecimals(): void
    {
        // 10% of 99.99 = 9.999 -> 10.00 (rounds up).
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 99.99)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'percent', 'percent' => '10']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10.0, $refund->lastArguments->getAdjustmentPositive());
    }

    public function testFixedModeRefundsTheFixedAmount(): void
    {
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 200.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '50']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(50.0, $refund->lastArguments->getAdjustmentPositive());
        $this->assertSame(50.0, $result->getOutput()['refund_amount']);
    }

    public function testFixedModeCapsAtRefundableRemainder(): void
    {
        // paid 100, already refunded 80 -> only 20 remains; a $50 request caps to $20.
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 100.0, refunded: 80.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '50']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(20.0, $refund->lastArguments->getAdjustmentPositive());
        $this->assertSame(20.0, $result->getOutput()['refund_amount']);
    }

    public function testFixedModeFailsWhenNothingLeftToRefund(): void
    {
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 100.0, refunded: 100.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '10']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertSame(0, $refund->calls, 'nothing-to-refund must never reach RefundOrder');
        $this->assertStringContainsString('nothing left to refund', (string)$result->getError());
    }

    public function testPercentModeRejectsOutOfRangePercent(): void
    {
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 200.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'percent', 'percent' => '150']);

        $this->assertTrue($result->isFailure());
        $this->assertSame(0, $refund->calls);
        $this->assertStringContainsString('between 1 and 100', (string)$result->getError());
    }

    public function testFixedModeRejectsNonPositiveAmount(): void
    {
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 200.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '0']);

        $this->assertTrue($result->isFailure());
        $this->assertSame(0, $refund->calls);
        $this->assertStringContainsString('greater than 0', (string)$result->getError());
    }

    public function testUnknownModeIsTerminalFailure(): void
    {
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 200.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), ['mode' => 'half']);

        $this->assertTrue($result->isFailure());
        $this->assertSame(0, $refund->calls);
        $this->assertStringContainsString('Invalid credit memo mode', (string)$result->getError());
    }

    public function testFullModeIsUnchangedAndPassesNoArguments(): void
    {
        $refund = $this->recordingRefund(creditmemoId: 700);
        $action = new CreateCreditmemo(
            $this->repositoryReturning($this->order(paid: 200.0)),
            $refund,
            $this->argumentsFactory()
        );

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls);
        $this->assertSame([], $refund->lastItems);
        $this->assertNull($refund->lastArguments, 'full mode must not pass creation arguments');
        $this->assertSame(700, $result->getOutput()['creditmemo_id']);
        $this->assertFalse(array_key_exists('mode', $result->getOutput()), 'full mode output is unchanged');
    }

    public function testPartialModeWithoutArgumentsFactoryFailsCleanly(): void
    {
        $refund = $this->recordingRefund();
        // No arguments factory (defaults to null): partial modes cannot build
        // the adjustment and must fail terminally before RefundOrder.
        $action = new CreateCreditmemo($this->repositoryReturning($this->order(paid: 200.0)), $refund);

        $result = $action->execute($this->context(), ['mode' => 'percent', 'percent' => '25']);

        $this->assertTrue($result->isFailure());
        $this->assertSame(0, $refund->calls);
    }

    private function context(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 42));
    }

    private function order(float $paid, float $refunded = 0.0, bool $canCreditmemo = true): Order
    {
        return new class($paid, $refunded, $canCreditmemo) extends Order {
            public function __construct(
                private readonly float $paid,
                private readonly float $refunded,
                private readonly bool $canCreditmemoV
            ) {
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
                return Order::STATE_PROCESSING;
            }
            public function canCreditmemo(): bool
            {
                return $this->canCreditmemoV;
            }
            /**
             * @return float
             */
            public function getTotalPaid()
            {
                return $this->paid;
            }
            /**
             * @return float
             */
            public function getTotalRefunded()
            {
                return $this->refunded;
            }
        };
    }

    private function repositoryReturning(Order $order): OrderRepositoryInterface
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

    private function argumentsFactory(): CreditmemoCreationArgumentsInterfaceFactory
    {
        return new class extends CreditmemoCreationArgumentsInterfaceFactory {
            public function create(array $data = [])
            {
                return new class implements CreditmemoCreationArgumentsInterface {
                    private ?float $adjustmentPositive = null;
                    public function setAdjustmentPositive($amount)
                    {
                        $this->adjustmentPositive = (float)$amount;
                        return $this;
                    }
                    public function getAdjustmentPositive()
                    {
                        return $this->adjustmentPositive;
                    }
                };
            }
        };
    }

    private function recordingRefund(int $creditmemoId = 500)
    {
        return new class($creditmemoId) implements RefundOrderInterface {
            public int $calls = 0;
            public ?int $lastOrderId = null;
            public ?array $lastItems = null;
            public ?bool $lastNotify = null;
            public ?CreditmemoCreationArgumentsInterface $lastArguments = null;
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
                $this->lastArguments = $arguments;
                return $this->creditmemoId;
            }
        };
    }
}
