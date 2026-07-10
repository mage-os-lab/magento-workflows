<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Test\Unit\Action\Order;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsSales\Action\Order\SendEmail;
use PHPUnit\Framework\TestCase;

/**
 * ORD-A2: order.send_email. order_confirmation resends via
 * OrderManagementInterface::notify; comment emails the latest customer-visible
 * comment via OrderCommentSender (composing with order.add_comment, not
 * duplicating it). A canceled order is a terminal failure in both modes.
 */
class SendEmailTest extends TestCase
{
    public function testOrderConfirmationNotifiesViaOrderManagement(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail($this->repositoryReturning($this->order()), $mgmt, $sender);

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['notified']);
        $this->assertSame([42], $mgmt->notifiedOrderIds);
        $this->assertSame(0, $sender->calls, 'confirmation mode must not touch the comment sender');
    }

    public function testOrderConfirmationDefaultsWhenNoTypeGiven(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail($this->repositoryReturning($this->order()), $mgmt, $this->recordingCommentSender());

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([42], $mgmt->notifiedOrderIds);
    }

    public function testOrderConfirmationNotifyFalseIsRetryableFailure(): void
    {
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(returns: false),
            $this->recordingCommentSender()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
    }

    public function testOrderConfirmationLocalizedExceptionIsTerminal(): void
    {
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(throws: new LocalizedException(new Phrase('template missing'))),
            $this->recordingCommentSender()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('template missing', (string)$result->getError());
    }

    public function testOrderConfirmationGenericExceptionIsRetryable(): void
    {
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(throws: new \RuntimeException('smtp timeout')),
            $this->recordingCommentSender()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
    }

    public function testCommentModeSendsLatestVisibleCommentWithMarkerStripped(): void
    {
        $order = $this->order(histories: [
            $this->history('Internal note', visible: false, createdAt: '2026-01-01 12:00:00'),
            $this->history('Older visible note', visible: true, createdAt: '2026-01-01 10:00:00'),
            $this->history('Your parcel shipped <!-- mageos-workflows:abc123 -->', visible: true, createdAt: '2026-01-01 11:00:00'),
        ]);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail($this->repositoryReturning($order), $this->orderManagement(returns: true), $sender);

        $result = $action->execute($this->context(), ['email_type' => 'comment']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['comment_sent']);
        $this->assertSame(1, $sender->calls);
        $this->assertSame('Your parcel shipped', $sender->lastComment, 'latest visible comment, marker stripped');
        $this->assertTrue($sender->lastNotify);
    }

    public function testCommentModeSkipsWhenNoVisibleComment(): void
    {
        $order = $this->order(histories: [
            $this->history('Internal only', visible: false, createdAt: '2026-01-01 12:00:00'),
        ]);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail($this->repositoryReturning($order), $this->orderManagement(returns: true), $sender);

        $result = $action->execute($this->context(), ['email_type' => 'comment']);

        $this->assertSame('skipped', $result->getStatus());
        $this->assertSame(0, $sender->calls);
    }

    public function testCanceledOrderIsTerminalFailure(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail(
            $this->repositoryReturning($this->order(state: Order::STATE_CANCELED)),
            $mgmt,
            $this->recordingCommentSender()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $mgmt->notifiedOrderIds, 'canceled order must not be notified');
        $this->assertStringContainsString('canceled', (string)$result->getError());
    }

    public function testInvalidEmailTypeIsTerminalFailure(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail($this->repositoryReturning($this->order()), $mgmt, $this->recordingCommentSender());

        $result = $action->execute($this->context(), ['email_type' => 'invoice']);

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $mgmt->notifiedOrderIds);
        $this->assertStringContainsString('Invalid email_type', (string)$result->getError());
    }

    public function testSimulateNeverSends(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail($this->repositoryReturning($this->order()), $mgmt, $sender);

        $result = $action->simulate($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame([], $mgmt->notifiedOrderIds);
        $this->assertSame(0, $sender->calls);
    }

    private function context(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 42));
    }

    /**
     * @param array<int, object> $histories
     */
    private function order(string $state = Order::STATE_PROCESSING, array $histories = []): Order
    {
        return new class($state, $histories) extends Order {
            /**
             * @param array<int, object> $histories
             */
            public function __construct(private readonly string $stateV, private readonly array $historiesV)
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
                return $this->stateV;
            }
            public function getStatusHistories(): array
            {
                return $this->historiesV;
            }
        };
    }

    private function history(string $comment, bool $visible, string $createdAt): object
    {
        return new class($comment, $visible, $createdAt) {
            public function __construct(
                private readonly string $comment,
                private readonly bool $visible,
                private readonly string $createdAt
            ) {
            }
            public function getComment(): string
            {
                return $this->comment;
            }
            public function getIsVisibleOnFront(): bool
            {
                return $this->visible;
            }
            public function getCreatedAt(): string
            {
                return $this->createdAt;
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

    private function orderManagement(?bool $returns = null, ?\Throwable $throws = null): OrderManagementInterface
    {
        return new class($returns, $throws) implements OrderManagementInterface {
            /** @var int[] */
            public array $notifiedOrderIds = [];
            public function __construct(private readonly ?bool $returns, private readonly ?\Throwable $throws)
            {
            }
            public function notify($id)
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                $this->notifiedOrderIds[] = (int)$id;
                return (bool)$this->returns;
            }
            // Full OrderManagementInterface surface (only notify() is exercised).
            public function cancel($id) { throw new \BadMethodCallException(__METHOD__); }
            public function getCommentsList($id) { throw new \BadMethodCallException(__METHOD__); }
            public function addComment($id, $statusHistory) { throw new \BadMethodCallException(__METHOD__); }
            public function getStatus($id) { throw new \BadMethodCallException(__METHOD__); }
            public function hold($id) { throw new \BadMethodCallException(__METHOD__); }
            public function unHold($id) { throw new \BadMethodCallException(__METHOD__); }
            public function place($order) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    private function recordingCommentSender(): OrderCommentSender
    {
        return new class extends OrderCommentSender {
            public int $calls = 0;
            public ?string $lastComment = null;
            public ?bool $lastNotify = null;
            public function __construct()
            {
            }
            public function send(Order $order, $notify = false, $comment = '')
            {
                $this->calls++;
                $this->lastComment = (string)$comment;
                $this->lastNotify = (bool)$notify;
                return true;
            }
        };
    }
}
