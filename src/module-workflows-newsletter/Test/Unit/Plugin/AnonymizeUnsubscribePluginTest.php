<?php
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Test\Unit\Plugin;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsCustomer\Action\Customer\Anonymize;
use MageOS\WorkflowsNewsletter\Plugin\AnonymizeUnsubscribePlugin;
use PHPUnit\Framework\TestCase;

/**
 * Pins the newsletter unsubscription behaviour extracted out of
 * customer.anonymize in domain-packs S5. These assertions moved here from
 * MageOS\WorkflowsCustomer\Test\Unit\Action\Customer\AnonymizeBehaviorTest:
 * the unsubscribe runs BEFORE the action's save, an unsubscribe infrastructure
 * failure blocks the save (retryable, action never proceeds), a missing
 * subscription is tolerated, and a successful anonymize carries the
 * 'unsubscribed' output marker. The confirm gate keeps an unconfirmed request
 * a pure no-op.
 */
class AnonymizeUnsubscribePluginTest extends TestCase
{
    private const CUSTOMER_ID = 123;

    public function testUnsubscribesBeforeProceedAndMarksOutputOnSuccess(): void
    {
        $log = new \ArrayObject();
        $subscriptions = $this->createSubscriptionManager($log);
        $plugin = new AnonymizeUnsubscribePlugin($subscriptions);

        $proceed = function (ExecutionContextInterface $ctx, array $config) use ($log): ActionResultInterface {
            $log->append('save');
            return ActionResult::success(['email' => 'anonymized+123@invalid.example']);
        };

        $result = $plugin->aroundExecute($this->anonymize(), $proceed, $this->context(), ['confirm' => true]);

        $this->assertSame(
            ['unsubscribe', 'save'],
            $log->getArrayCopy(),
            'Unsubscribe must run BEFORE the action save, while the real email still identifies the subscriber'
        );
        $this->assertSame(1, $subscriptions->calls);
        $this->assertSame(self::CUSTOMER_ID, $subscriptions->lastCustomerId);
        $this->assertSame(1, $subscriptions->lastStoreId);
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['unsubscribed']);
        $this->assertSame('anonymized+123@invalid.example', $result->getOutput()['email']);
    }

    public function testUnconfirmedRequestIsAPureNoOp(): void
    {
        $log = new \ArrayObject();
        $subscriptions = $this->createSubscriptionManager($log);
        $plugin = new AnonymizeUnsubscribePlugin($subscriptions);

        $terminal = ActionResult::failure('customer.anonymize requires confirm: true');
        $proceed = function () use ($log, $terminal): ActionResultInterface {
            $log->append('save');
            return $terminal;
        };

        $result = $plugin->aroundExecute($this->anonymize(), $proceed, $this->context(), []);

        $this->assertSame(0, $subscriptions->calls, 'An unconfirmed request must never unsubscribe');
        $this->assertSame(['save'], $log->getArrayCopy());
        $this->assertSame($terminal, $result, 'The action result is returned untouched');
        $this->assertFalse(array_key_exists('unsubscribed', $result->getOutput()));
    }

    public function testMissingSubscriptionIsToleratedAndActionStillAnonymizes(): void
    {
        $log = new \ArrayObject();
        $subscriptions = $this->createSubscriptionManager($log);
        $subscriptions->throwOnUnsubscribe = new NoSuchEntityException();
        $plugin = new AnonymizeUnsubscribePlugin($subscriptions);

        $proceed = function () use ($log): ActionResultInterface {
            $log->append('save');
            return ActionResult::success(['email' => 'anonymized+123@invalid.example']);
        };

        $result = $plugin->aroundExecute($this->anonymize(), $proceed, $this->context(), ['confirm' => true]);

        $this->assertSame(['save'], $log->getArrayCopy(), 'A missing subscription must not block anonymization');
        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->getOutput()['unsubscribed']);
    }

    public function testUnsubscribeInfrastructureFailureIsRetryableAndActionNeverProceeds(): void
    {
        $log = new \ArrayObject();
        $subscriptions = $this->createSubscriptionManager($log);
        $subscriptions->throwOnUnsubscribe = new \RuntimeException('Connection refused');
        $plugin = new AnonymizeUnsubscribePlugin($subscriptions);

        $proceed = function () use ($log): ActionResultInterface {
            $log->append('save');
            return ActionResult::success();
        };

        $result = $plugin->aroundExecute($this->anonymize(), $proceed, $this->context(), ['confirm' => true]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('unsubscribe', (string)$result->getError());
        $this->assertSame([], $log->getArrayCopy(), 'The customer must not be scrubbed until the unsubscribe succeeds');
    }

    public function testUnsubscribeHappensBeforeAFailedSaveIsPropagated(): void
    {
        $log = new \ArrayObject();
        $subscriptions = $this->createSubscriptionManager($log);
        $plugin = new AnonymizeUnsubscribePlugin($subscriptions);

        $failure = ActionResult::failure('Could not anonymize customer: Deadlock', true);
        $proceed = function () use ($log, $failure): ActionResultInterface {
            $log->append('save');
            return $failure;
        };

        $result = $plugin->aroundExecute($this->anonymize(), $proceed, $this->context(), ['confirm' => true]);

        $this->assertSame(['unsubscribe', 'save'], $log->getArrayCopy());
        $this->assertSame(1, $subscriptions->calls, 'Unsubscribe already happened before the failed save');
        $this->assertSame($failure, $result, 'A non-success result is returned untouched (no unsubscribed marker)');
    }

    public function testSkippedResultIsReturnedUntouched(): void
    {
        $log = new \ArrayObject();
        $subscriptions = $this->createSubscriptionManager($log);
        $plugin = new AnonymizeUnsubscribePlugin($subscriptions);

        $skipped = ActionResult::skipped('Customer is already anonymized');
        $proceed = fn (): ActionResultInterface => $skipped;

        $result = $plugin->aroundExecute($this->anonymize(), $proceed, $this->context(), ['confirm' => true]);

        // Documented divergence: because a plugin cannot slot between the
        // action's skip check and its save, an already-anonymized customer
        // receives one extra idempotent unsubscribe before SKIPPED is returned.
        $this->assertSame(1, $subscriptions->calls);
        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse(array_key_exists('unsubscribed', $result->getOutput()));
    }

    private function anonymize(): Anonymize
    {
        // The plugin never calls the subject; a throwaway repository suffices.
        $repository = new class implements CustomerRepositoryInterface {
            public function getById($customerId) { throw new \BadMethodCallException(__METHOD__); }
            public function save($customer, $passwordHash = null) { throw new \BadMethodCallException(__METHOD__); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
        return new Anonymize($repository);
    }

    private function context(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: self::CUSTOMER_ID, storeId: 1));
    }

    private function createSubscriptionManager(\ArrayObject $log)
    {
        return new class($log) implements SubscriptionManagerInterface {
            public int $calls = 0;
            public ?int $lastCustomerId = null;
            public ?int $lastStoreId = null;
            public ?\Throwable $throwOnUnsubscribe = null;
            public function __construct(private readonly \ArrayObject $log)
            {
            }
            public function unsubscribeCustomer($customerId, $storeId): Subscriber
            {
                $this->calls++;
                $this->lastCustomerId = (int)$customerId;
                $this->lastStoreId = (int)$storeId;
                if ($this->throwOnUnsubscribe !== null) {
                    throw $this->throwOnUnsubscribe;
                }
                $this->log->append('unsubscribe');
                return new class extends Subscriber {
                    public function __construct()
                    {
                    }
                };
            }
            public function subscribe($email, $storeId): Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function unsubscribe($email, $storeId, $confirmCode): Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function subscribeCustomer($customerId, $storeId): Subscriber { throw new \BadMethodCallException(__METHOD__); }
        };
    }
}
