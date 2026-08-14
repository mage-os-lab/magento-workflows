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
use MageOS\Workflows\Model\Idempotency\SendClaimStoreInterface;
use MageOS\Workflows\Model\Idempotency\SendOnceGuard;
use MageOS\Workflows\Test\Unit\Stub\FakeSendClaimStore;
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
    private const SCOPE = 'order.send_email';

    public function testOrderConfirmationNotifiesViaOrderManagement(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail($this->repositoryReturning($this->order()), $mgmt, $sender, $this->guard());

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['notified']);
        $this->assertSame([42], $mgmt->notifiedOrderIds);
        $this->assertSame(0, $sender->calls, 'confirmation mode must not touch the comment sender');
    }

    public function testOrderConfirmationDefaultsWhenNoTypeGiven(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $mgmt,
            $this->recordingCommentSender(),
            $this->guard()
        );

        $result = $action->execute($this->context(), []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([42], $mgmt->notifiedOrderIds);
    }

    public function testOrderConfirmationNotifyFalseIsTerminalAndKeepsTheClaim(): void
    {
        // notify() swallows the transport error and reports one flat false, so
        // "nothing was sent" and "the MTA took it and the link dropped" are
        // indistinguishable. The claim stands and the step fails terminally
        // rather than redelivering into a possible second copy.
        $store = new FakeSendClaimStore();
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(returns: false),
            $this->recordingCommentSender(),
            $this->guard($store)
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable(), 'an unconfirmable send must not be retried automatically');
        $this->assertStringContainsString('will NOT be retried', (string)$result->getError());
        $this->assertSame(
            SendClaimStoreInterface::STATUS_CLAIMED,
            $store->statusOf(self::SCOPE, $this->dedupeKey()),
            'the claim is retained, unconfirmed'
        );
    }

    public function testOrderConfirmationLocalizedExceptionIsTerminal(): void
    {
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(throws: new LocalizedException(new Phrase('template missing'))),
            $this->recordingCommentSender(),
            $this->guard()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('template missing', (string)$result->getError());
    }

    public function testOrderConfirmationExceptionFromTheSendIsTerminalToo(): void
    {
        // Same reasoning as the false return: the send call was entered, so
        // the outcome is unknowable and a retry could double-mail.
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(throws: new \RuntimeException('smtp timeout')),
            $this->recordingCommentSender(),
            $this->guard()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('smtp timeout', (string)$result->getError());
    }

    public function testCommentModeSendsLatestVisibleCommentWithMarkerStripped(): void
    {
        $order = $this->order(histories: [
            $this->history('Internal note', visible: false, createdAt: '2026-01-01 12:00:00'),
            $this->history('Older visible note', visible: true, createdAt: '2026-01-01 10:00:00'),
            $this->history('Your parcel shipped <!-- mageos-workflows:abc123 -->', visible: true, createdAt: '2026-01-01 11:00:00'),
        ]);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail(
            $this->repositoryReturning($order),
            $this->orderManagement(returns: true),
            $sender,
            $this->guard()
        );

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
        $action = new SendEmail(
            $this->repositoryReturning($order),
            $this->orderManagement(returns: true),
            $sender,
            $this->guard()
        );

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
            $this->recordingCommentSender(),
            $this->guard()
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $mgmt->notifiedOrderIds, 'canceled order must not be notified');
        $this->assertStringContainsString('canceled', (string)$result->getError());
    }

    public function testInvalidEmailTypeIsTerminalFailure(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $mgmt,
            $this->recordingCommentSender(),
            $this->guard()
        );

        $result = $action->execute($this->context(), ['email_type' => 'invoice']);

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $mgmt->notifiedOrderIds);
        $this->assertStringContainsString('Invalid email_type', (string)$result->getError());
    }

    public function testSimulateNeverSends(): void
    {
        $mgmt = $this->orderManagement(returns: true);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail($this->repositoryReturning($this->order()), $mgmt, $sender, $this->guard());

        $result = $action->simulate($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['simulated']);
        $this->assertSame([], $mgmt->notifiedOrderIds);
        $this->assertSame(0, $sender->calls);
    }

    // -- durable send-once guard (finding 10) --------------------------------

    public function testRedeliveryOfTheSameStepDoesNotEmailTwice(): void
    {
        $store = new FakeSendClaimStore();
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $mgmt,
            $this->recordingCommentSender(),
            $this->guard($store)
        );
        $config = ['email_type' => 'order_confirmation'];

        $first = $action->execute($this->context(), $config);
        $second = $action->execute($this->context(), $config);

        $this->assertTrue($first->isSuccess());
        $this->assertSame('skipped', $second->getStatus());
        $this->assertSame([42], $mgmt->notifiedOrderIds, 'the customer is notified exactly once');
        $this->assertStringContainsString('Duplicate', (string)$second->getOutput()['reason']);
    }

    public function testAClaimFromACrashedAttemptSuppressesTheRedeliveryHonestly(): void
    {
        // The crash window: delivery #1 claimed and sent, then died before the
        // step row was marked complete. The redelivery must not re-notify, and
        // must not pretend it knows the mail arrived.
        $store = new FakeSendClaimStore();
        $store->seedClaim(self::SCOPE, $this->dedupeKey());
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $mgmt,
            $this->recordingCommentSender(),
            $this->guard($store)
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertSame('skipped', $result->getStatus());
        $this->assertSame([], $mgmt->notifiedOrderIds);
        $this->assertStringContainsString('never confirmed', (string)$result->getOutput()['reason']);
    }

    public function testCommentModeIsGuardedByTheSameClaim(): void
    {
        $store = new FakeSendClaimStore();
        $order = $this->order(histories: [
            $this->history('Your parcel shipped', visible: true, createdAt: '2026-01-01 11:00:00'),
        ]);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail(
            $this->repositoryReturning($order),
            $this->orderManagement(returns: true),
            $sender,
            $this->guard($store)
        );
        $config = ['email_type' => 'comment'];

        $first = $action->execute($this->context(), $config);
        $second = $action->execute($this->context(), $config);

        $this->assertTrue($first->isSuccess());
        $this->assertSame('skipped', $second->getStatus());
        $this->assertSame(1, $sender->calls);
    }

    public function testNothingToSendLeavesNoClaimBehind(): void
    {
        // "No visible comment" is resolved BEFORE claiming, so a later run
        // that does have a comment is not suppressed by a wasted claim.
        $store = new FakeSendClaimStore();
        $emptyOrder = $this->order(histories: [
            $this->history('Internal only', visible: false, createdAt: '2026-01-01 12:00:00'),
        ]);
        $sender = $this->recordingCommentSender();
        $action = new SendEmail(
            $this->repositoryReturning($emptyOrder),
            $this->orderManagement(returns: true),
            $sender,
            $this->guard($store)
        );

        $skip = $action->execute($this->context(), ['email_type' => 'comment']);

        $this->assertSame('skipped', $skip->getStatus());
        $this->assertSame([], $store->rows, 'a step with nothing to send must not burn its claim');
    }

    public function testAnUnanswerableClaimParksTheStepInsteadOfSending(): void
    {
        $store = new FakeSendClaimStore();
        $store->failClaim = new \RuntimeException('SQLSTATE[HY000]: server has gone away');
        $mgmt = $this->orderManagement(returns: true);
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $mgmt,
            $this->recordingCommentSender(),
            $this->guard($store)
        );

        $result = $action->execute($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertSame([], $mgmt->notifiedOrderIds, 'no send may happen under an unanswered guard');
    }

    public function testSimulateNeverClaims(): void
    {
        $store = new FakeSendClaimStore();
        $action = new SendEmail(
            $this->repositoryReturning($this->order()),
            $this->orderManagement(returns: true),
            $this->recordingCommentSender(),
            $this->guard($store)
        );

        $action->simulate($this->context(), ['email_type' => 'order_confirmation']);

        $this->assertSame([], $store->rows, 'simulation must leave no claim that would suppress the real run');
    }

    public function testTheGuardDependencyIsRequiredSoItCannotArriveNull(): void
    {
        // The ObjectManager does not auto-inject a parameter that has a
        // default value: an "optional" guard would arrive null and this action
        // would double-mail customers again.
        $parameters = (new \ReflectionClass(SendEmail::class))->getConstructor()->getParameters();
        $guard = $parameters[3];

        $this->assertSame('sendOnceGuard', $guard->getName());
        $this->assertSame(SendOnceGuard::class, (string)$guard->getType());
        $this->assertFalse($guard->isDefaultValueAvailable());
        $this->assertFalse($guard->allowsNull());
    }

    private function guard(?FakeSendClaimStore $store = null): SendOnceGuard
    {
        return new SendOnceGuard($store ?? new FakeSendClaimStore());
    }

    /**
     * The key the action derives: execution UUID + step key, the action code
     * standing in for an unset current step (AbstractAction::stepKey).
     */
    private function dedupeKey(): string
    {
        return $this->context()->getDedupeKey(self::SCOPE);
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
