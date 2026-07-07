<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsActionsCore\Action\Customer\Anonymize;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for customer.anonymize beyond the confirm gate (pinned
 * in AnonymizeTest). Pins the class docblock promises: the anonymized-email
 * pattern doubles as the idempotency marker (already-anonymized skips, no
 * second save), and the newsletter unsubscribe runs BEFORE the customer save
 * so a failed save can retry the whole sequence safely.
 */
class AnonymizeBehaviorTest extends TestCase
{
    private const CUSTOMER_ID = 123;
    private const ANONYMIZED_EMAIL = 'anonymized+123@invalid.example';

    public function testExecuteSkipsAlreadyAnonymizedCustomerWithoutSavingOrUnsubscribing(): void
    {
        $log = new \ArrayObject();
        $customer = $this->createFakeCustomer(self::ANONYMIZED_EMAIL);
        $repository = $this->createRepository($log, $customer);
        $subscriptions = $this->createSubscriptionManager($log);
        $action = new Anonymize($repository, $subscriptions);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $repository->saveCalls, 'Redelivery must not save an already-anonymized customer again');
        $this->assertSame(0, $subscriptions->calls, 'No unsubscribe for an already-anonymized customer');
        $this->assertStringContainsString('already anonymized', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteSkipsAlreadyAnonymizedCustomerCaseInsensitively(): void
    {
        $log = new \ArrayObject();
        $customer = $this->createFakeCustomer('Anonymized+123@Invalid.Example');
        $repository = $this->createRepository($log, $customer);
        $subscriptions = $this->createSubscriptionManager($log);
        $action = new Anonymize($repository, $subscriptions);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $repository->saveCalls);
        $this->assertSame(0, $subscriptions->calls);
    }

    public function testExecuteUnsubscribesBeforeSavingAndScrubsAllPiiFields(): void
    {
        $log = new \ArrayObject();
        $customer = $this->createFakeCustomer('jane.doe@example.com');
        $repository = $this->createRepository($log, $customer);
        $subscriptions = $this->createSubscriptionManager($log);
        $action = new Anonymize($repository, $subscriptions);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(
            ['unsubscribe', 'save'],
            $log->getArrayCopy(),
            'Unsubscribe must run BEFORE the save, while the real email still identifies the subscriber'
        );
        $this->assertSame(1, $subscriptions->calls);
        $this->assertSame(self::CUSTOMER_ID, $subscriptions->lastCustomerId);
        $this->assertSame(1, $subscriptions->lastStoreId);
        $this->assertSame(1, $repository->saveCalls);
        $this->assertSame('Anonymized', $customer->set['firstname']);
        $this->assertSame('Customer', $customer->set['lastname']);
        $this->assertSame(self::ANONYMIZED_EMAIL, $customer->set['email']);
        $this->assertNull($customer->set['dob']);
        $this->assertNull($customer->set['taxvat']);
        $this->assertNull($customer->set['gender']);
        $this->assertNull($customer->set['middlename']);
        $this->assertNull($customer->set['prefix']);
        $this->assertNull($customer->set['suffix']);
        $this->assertSame(self::ANONYMIZED_EMAIL, $result->getOutput()['email']);
        $this->assertTrue($result->getOutput()['unsubscribed']);
    }

    public function testExecuteSaveFailureIsRetryableAndUnsubscribeAlreadyHappened(): void
    {
        $log = new \ArrayObject();
        $customer = $this->createFakeCustomer('jane.doe@example.com');
        $repository = $this->createRepository($log, $customer);
        $repository->throwOnSave = new \RuntimeException('Deadlock found when trying to get lock');
        $subscriptions = $this->createSubscriptionManager($log);
        $action = new Anonymize($repository, $subscriptions);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'A failed save must retry; re-unsubscribing on retry is a no-op');
        $this->assertStringContainsString('Deadlock', (string)$result->getError());
        $this->assertSame(1, $subscriptions->calls, 'Unsubscribe already happened before the failed save');
    }

    public function testExecuteProceedsToSaveWhenCustomerHasNoNewsletterSubscription(): void
    {
        $log = new \ArrayObject();
        $customer = $this->createFakeCustomer('jane.doe@example.com');
        $repository = $this->createRepository($log, $customer);
        $subscriptions = $this->createSubscriptionManager($log);
        $subscriptions->throwOnUnsubscribe = new NoSuchEntityException();
        $action = new Anonymize($repository, $subscriptions);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertTrue($result->isSuccess(), 'A missing subscription row must not block anonymization');
        $this->assertSame(1, $repository->saveCalls);
        $this->assertSame(self::ANONYMIZED_EMAIL, $customer->set['email']);
    }

    public function testExecuteUnsubscribeInfrastructureFailureIsRetryableAndCustomerIsNotSaved(): void
    {
        $log = new \ArrayObject();
        $customer = $this->createFakeCustomer('jane.doe@example.com');
        $repository = $this->createRepository($log, $customer);
        $subscriptions = $this->createSubscriptionManager($log);
        $subscriptions->throwOnUnsubscribe = new \RuntimeException('Connection refused');
        $action = new Anonymize($repository, $subscriptions);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable());
        $this->assertSame(0, $repository->saveCalls, 'Customer must keep the real email until the unsubscribe succeeds');
        $this->assertStringContainsString('unsubscribe', (string)$result->getError());
    }

    private function createContext(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: self::CUSTOMER_ID, storeId: 1));
    }

    private function createFakeCustomer(string $email): object
    {
        return new class($email) {
            /** @var array<string, mixed> every value passed to a setter */
            public array $set = [];
            public function __construct(private string $email)
            {
            }
            public function getEmail()
            {
                return $this->email;
            }
            public function setEmail($email)
            {
                $this->set['email'] = $email;
                $this->email = (string)$email;
                return $this;
            }
            public function setFirstname($value)
            {
                $this->set['firstname'] = $value;
                return $this;
            }
            public function setLastname($value)
            {
                $this->set['lastname'] = $value;
                return $this;
            }
            public function setDob($value)
            {
                $this->set['dob'] = $value;
                return $this;
            }
            public function setTaxvat($value)
            {
                $this->set['taxvat'] = $value;
                return $this;
            }
            public function setGender($value)
            {
                $this->set['gender'] = $value;
                return $this;
            }
            public function setMiddlename($value)
            {
                $this->set['middlename'] = $value;
                return $this;
            }
            public function setPrefix($value)
            {
                $this->set['prefix'] = $value;
                return $this;
            }
            public function setSuffix($value)
            {
                $this->set['suffix'] = $value;
                return $this;
            }
        };
    }

    private function createRepository(\ArrayObject $log, object $customer)
    {
        return new class($log, $customer) implements CustomerRepositoryInterface {
            public int $saveCalls = 0;
            public ?\Throwable $throwOnSave = null;
            public function __construct(
                private readonly \ArrayObject $log,
                private readonly object $customer
            ) {
            }
            public function getById($customerId)
            {
                return $this->customer;
            }
            public function save($customer, $passwordHash = null)
            {
                $this->saveCalls++;
                if ($this->throwOnSave !== null) {
                    throw $this->throwOnSave;
                }
                $this->log->append('save');
                return $customer;
            }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
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
