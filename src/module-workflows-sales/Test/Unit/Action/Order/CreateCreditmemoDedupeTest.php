<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\CreateCreditmemo;
use PHPUnit\Framework\TestCase;

/**
 * Redelivery safety for order.create_creditmemo — the money-losing case.
 *
 * canCreditmemo() is a STATE guard, and it only incidentally blocked a second
 * refund in `full` mode: percent/fixed issue adjustment-only memos that leave
 * the order still creditmemo-able, so under at-least-once delivery (docs/08) a
 * redelivered message refunded real money twice. The guard that actually holds
 * for every mode is the dedupe marker — the same execution-UUID + step-key
 * marker order.add_comment uses (pinned by AddCommentTest), carried this time
 * on a credit-memo comment created in the SAME transaction as the memo.
 *
 * These tests pin both halves: the marker is attached on every mode's first
 * run, and it is honored on redelivery — per step, per execution, before any
 * refund call.
 */
class CreateCreditmemoDedupeTest extends TestCase
{
    use CreditmemoRefundDoubles;

    private const UUID = 'exec-uuid-1';
    private const STEP = 'refund_step';
    private const MARKER = '<!-- mageos-workflows:' . self::UUID . ':' . self::STEP . ' -->';

    // ------------------------------------------------------------------
    // First run: the marker rides into the memo, for every mode
    // ------------------------------------------------------------------

    public function testFullModeAttachesTheDedupeMarkerAsAnInvisibleCreditmemoComment(): void
    {
        $refund = $this->recordingRefund(creditmemoId: 501);
        $action = $this->action($refund, memos: []);

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls);
        $this->assertSame(501, $result->getOutput()['creditmemo_id']);
        // The comment handed to RefundOrder carries this execution+step's marker.
        $this->assertNotNull($refund->lastComment);
        $this->assertStringContainsString(self::MARKER, (string)$refund->lastComment->getComment());
        // ...and is invisible on the storefront, with appendComment false so it
        // never becomes a notified customer note (and never reaches the email).
        $this->assertFalse((bool)$refund->lastComment->getIsVisibleOnFront());
        $this->assertFalse((bool)$refund->lastAppendComment);
    }

    public function testPercentModeAttachesTheMarkerAlongsideTheAdjustmentArguments(): void
    {
        $refund = $this->recordingRefund(creditmemoId: 502);
        $action = $this->action($refund, memos: [], paid: 200.0, withArgumentsFactory: true);

        $result = $action->execute($this->context(), ['mode' => 'percent', 'percent' => '25']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(50.0, $result->getOutput()['refund_amount']);
        $this->assertSame(50.0, $refund->lastArguments->getAdjustmentPositive());
        $this->assertStringContainsString(self::MARKER, (string)$refund->lastComment->getComment());
    }

    public function testFixedModeAttachesTheMarkerAlongsideTheAdjustmentArguments(): void
    {
        $refund = $this->recordingRefund(creditmemoId: 503);
        $action = $this->action($refund, memos: [], paid: 200.0, withArgumentsFactory: true);

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '30']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(30.0, $refund->lastArguments->getAdjustmentPositive());
        $this->assertStringContainsString(self::MARKER, (string)$refund->lastComment->getComment());
    }

    public function testTheMarkerScanIsScopedToThisOrderById(): void
    {
        $refund = $this->recordingRefund();
        $action = $this->action($refund, memos: []);

        $action->execute($this->context(), []);

        $this->assertCount(1, $this->creditmemoFilters);
        $this->assertSame('order_id', $this->creditmemoFilters[0]['field']);
        $this->assertSame(42, $this->creditmemoFilters[0]['value']);
        $this->assertSame('eq', $this->creditmemoFilters[0]['type']);
    }

    // ------------------------------------------------------------------
    // Redelivery: the marker is found, nothing is refunded again
    // ------------------------------------------------------------------

    public function testRedeliveryFindsTheMarkerAndSkipsWithoutRefunding(): void
    {
        // A previous delivery of this exact execution UUID + step key already
        // created memo 900; its comment carries the marker.
        $refund = $this->recordingRefund();
        $action = $this->action($refund, memos: [
            $this->markedCreditmemo(900, ['Refund issued by a Mage-OS workflow. ' . self::MARKER]),
        ], paid: 200.0, withArgumentsFactory: true);

        $result = $action->execute($this->context(), ['mode' => 'percent', 'percent' => '25']);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $refund->calls, 'A redelivered partial refund must never reach RefundOrder');
        $this->assertStringContainsString('already refunded by this step', (string)($result->getOutput()['reason'] ?? ''));
        // The memo the first delivery created is recoverable from the output.
        $this->assertSame(900, $result->getOutput()['creditmemo_id'] ?? null);
    }

    public function testFixedModeRedeliveryIsAlsoBlocked(): void
    {
        $refund = $this->recordingRefund();
        $action = $this->action($refund, memos: [
            $this->markedCreditmemo(901, [self::MARKER]),
        ], paid: 200.0, withArgumentsFactory: true);

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '30']);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $refund->calls);
        $this->assertSame(901, $result->getOutput()['creditmemo_id'] ?? null);
    }

    public function testMarkerBeatsTheStateGuardSoFullModeRedeliveryReportsTheMemoItCreated(): void
    {
        // Full-mode redelivery: the order is no longer refundable, so the state
        // guard would also skip — but with a less useful answer. The marker
        // runs first and hands back the memo this step created.
        $refund = $this->recordingRefund();
        $action = $this->action($refund, memos: [
            $this->markedCreditmemo(902, [self::MARKER]),
        ], canCreditmemo: false);

        $result = $action->execute($this->context(), []);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $refund->calls);
        $this->assertSame(902, $result->getOutput()['creditmemo_id'] ?? null);
        $this->assertStringContainsString('already refunded by this step', (string)($result->getOutput()['reason'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Dedupe granularity: execution UUID + step key, nothing coarser
    // ------------------------------------------------------------------

    public function testAnotherStepOfTheSameExecutionIsNotDeduped(): void
    {
        // Two refund steps in one workflow are a legitimate design (e.g. a
        // goodwill credit after a partial one); only THIS step's marker counts.
        $refund = $this->recordingRefund(creditmemoId: 504);
        $action = $this->action($refund, memos: [
            $this->markedCreditmemo(900, ['<!-- mageos-workflows:' . self::UUID . ':other_step -->']),
        ], paid: 200.0, withArgumentsFactory: true);

        $result = $action->execute($this->context(), ['mode' => 'fixed', 'amount' => '10']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls);
        $this->assertSame(504, $result->getOutput()['creditmemo_id']);
    }

    public function testAMemoFromADifferentExecutionIsNotDeduped(): void
    {
        $refund = $this->recordingRefund(creditmemoId: 505);
        $action = $this->action($refund, memos: [
            $this->markedCreditmemo(900, ['<!-- mageos-workflows:other-uuid:' . self::STEP . ' -->']),
        ]);

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls);
    }

    public function testAManualMemoWithNoWorkflowCommentIsNotDeduped(): void
    {
        // Merchant-created memos (no comment at all, or an unrelated one) must
        // never be mistaken for this step's own work.
        $refund = $this->recordingRefund(creditmemoId: 506);
        $action = $this->action($refund, memos: [
            $this->markedCreditmemo(900, []),
            $this->markedCreditmemo(901, ['Refunded by hand', null]),
        ]);

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $refund->calls);
    }

    // ------------------------------------------------------------------
    // The dedupe question must always be answered before money moves
    // ------------------------------------------------------------------

    public function testAFailedMarkerScanIsRetryableAndNeverRefunds(): void
    {
        $refund = $this->recordingRefund();
        $action = new CreateCreditmemo(
            $this->orderRepositoryReturning($this->order()),
            $refund,
            $this->creditmemoRepositoryThatFails(new \RuntimeException('MySQL server has gone away')),
            $this->creditmemoCriteriaBuilder(),
            $this->creditmemoCommentFactory()
        );

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'An unanswered dedupe question must be redelivered, not refunded');
        $this->assertSame(0, $refund->calls);
        $this->assertStringContainsString('gone away', (string)$result->getError());
    }

    public function testSimulateNeitherScansForMarkersNorRefunds(): void
    {
        $refund = $this->recordingRefund();
        $action = $this->action($refund, memos: []);

        $result = $action->simulate($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $refund->calls);
        // simulate() creates no memo, so no memo can carry this execution+step's
        // marker: the scan would always miss and is deliberately skipped.
        $this->assertSame([], $this->creditmemoFilters);
        $this->assertSame([], $this->createdComments);
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    private function context(): ExecutionContext
    {
        $execution = new WorkflowExecutionStub(uuid: self::UUID, entityId: 42);
        $execution->setCurrentStep(self::STEP);
        return new ExecutionContext($execution);
    }

    /**
     * @param array<int, object> $memos credit memos already on the order
     */
    private function action(
        object $refund,
        array $memos,
        float $paid = 100.0,
        bool $canCreditmemo = true,
        bool $withArgumentsFactory = false
    ): CreateCreditmemo {
        return new CreateCreditmemo(
            $this->orderRepositoryReturning($this->order($paid, $canCreditmemo)),
            $refund,
            $this->creditmemoRepositoryWith($memos),
            $this->creditmemoCriteriaBuilder(),
            $this->creditmemoCommentFactory(),
            $withArgumentsFactory ? $this->argumentsFactory() : null
        );
    }

    private function order(float $paid = 100.0, bool $canCreditmemo = true): Order
    {
        return new class ($paid, $canCreditmemo) extends Order {
            public function __construct(private readonly float $paid, private readonly bool $refundable)
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
                return Order::STATE_PROCESSING;
            }
            public function canCreditmemo(): bool
            {
                return $this->refundable;
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
                return 0.0;
            }
        };
    }

    private function orderRepositoryReturning(Order $order): OrderRepositoryInterface
    {
        return new class ($order) implements OrderRepositoryInterface {
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

    /**
     * Recording RefundOrder double capturing the two arguments this suite is
     * about — the appendComment flag and the comment object itself — alongside
     * the creation arguments the partial modes pass.
     */
    private function recordingRefund(int $creditmemoId = 500)
    {
        return new class ($creditmemoId) implements RefundOrderInterface {
            public int $calls = 0;
            public ?bool $lastAppendComment = null;
            public $lastComment = null;
            public $lastArguments = null;
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
                $this->lastAppendComment = (bool)$appendComment;
                $this->lastComment = $comment;
                $this->lastArguments = $arguments;
                return $this->creditmemoId;
            }
        };
    }

    private function argumentsFactory(): CreditmemoCreationArgumentsInterfaceFactory
    {
        return new class extends CreditmemoCreationArgumentsInterfaceFactory {
            // Bypass the generated factory's DI constructor (ObjectManager) so
            // the double is instantiable under real Magento.
            public function __construct()
            {
            }
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
                    // Full CreditmemoCreationArgumentsInterface surface (unused here).
                    public function getShippingAmount() { throw new \BadMethodCallException(__METHOD__); }
                    public function setShippingAmount($amount) { throw new \BadMethodCallException(__METHOD__); }
                    public function getAdjustmentNegative() { throw new \BadMethodCallException(__METHOD__); }
                    public function setAdjustmentNegative($amount) { throw new \BadMethodCallException(__METHOD__); }
                    public function getExtensionAttributes() { throw new \BadMethodCallException(__METHOD__); }
                    public function setExtensionAttributes($ext) { throw new \BadMethodCallException(__METHOD__); }
                };
            }
        };
    }
}
