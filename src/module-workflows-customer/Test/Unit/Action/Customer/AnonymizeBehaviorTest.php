<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsCustomer\Action\Customer\Anonymize;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for customer.anonymize beyond the confirm gate (pinned
 * in AnonymizeTest). Pins the class docblock promises this action still owns
 * after domain-packs S5 extracted the newsletter unsubscribe into the
 * mage-os/workflows-newsletter plugin: the anonymized-email pattern doubles as
 * the idempotency marker (already-anonymized skips, no save), all PII fields
 * are scrubbed with empty strings, and a save failure is retryable.
 *
 * The unsubscribe-before-save ordering, the unsubscribe-failure-blocks-save
 * guarantee and the 'unsubscribed' output marker now live in
 * MageOS\WorkflowsNewsletter\Test\Unit\Plugin\AnonymizeUnsubscribePluginTest.
 */
class AnonymizeBehaviorTest extends TestCase
{
    private const CUSTOMER_ID = 123;
    private const ANONYMIZED_EMAIL = 'anonymized+123@invalid.example';

    public function testExecuteSkipsAlreadyAnonymizedCustomerWithoutSaving(): void
    {
        $customer = $this->createFakeCustomer(self::ANONYMIZED_EMAIL);
        $repository = $this->createRepository($customer);
        $action = new Anonymize($repository);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $repository->saveCalls, 'Redelivery must not save an already-anonymized customer again');
        $this->assertStringContainsString('already anonymized', (string)($result->getOutput()['reason'] ?? ''));
    }

    public function testExecuteSkipsAlreadyAnonymizedCustomerCaseInsensitively(): void
    {
        $customer = $this->createFakeCustomer('Anonymized+123@Invalid.Example');
        $repository = $this->createRepository($customer);
        $action = new Anonymize($repository);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertSame(ActionResultInterface::STATUS_SKIPPED, $result->getStatus());
        $this->assertSame(0, $repository->saveCalls);
    }

    public function testExecuteScrubsAllPiiFields(): void
    {
        $customer = $this->createFakeCustomer('jane.doe@example.com');
        $repository = $this->createRepository($customer);
        $action = new Anonymize($repository);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $repository->saveCalls);
        $this->assertSame('Anonymized', $customer->set['firstname']);
        $this->assertSame('Customer', $customer->set['lastname']);
        $this->assertSame(self::ANONYMIZED_EMAIL, $customer->set['email']);
        // Cleared with '' (not null): CustomerRepository::save() serializes the
        // DTO via toNestedArray(), which OMITS null attributes entirely - a
        // null "scrub" silently left the old PII in place on a real install
        // (integration-lane finding). Empty string survives serialization and
        // the EAV layer deletes the value, so it reads back null afterwards.
        $this->assertSame('', $customer->set['dob']);
        $this->assertSame('', $customer->set['taxvat']);
        $this->assertSame('', $customer->set['gender']);
        $this->assertSame('', $customer->set['middlename']);
        $this->assertSame('', $customer->set['prefix']);
        $this->assertSame('', $customer->set['suffix']);
        $this->assertSame(self::ANONYMIZED_EMAIL, $result->getOutput()['email']);
        // 'unsubscribed' is not set by the action itself; the newsletter pack's
        // plugin contributes it when installed.
        $this->assertFalse(array_key_exists('unsubscribed', $result->getOutput()));
    }

    public function testExecuteSaveFailureIsRetryable(): void
    {
        $customer = $this->createFakeCustomer('jane.doe@example.com');
        $repository = $this->createRepository($customer);
        $repository->throwOnSave = new \RuntimeException('Deadlock found when trying to get lock');
        $action = new Anonymize($repository);

        $result = $action->execute($this->createContext(), ['confirm' => true]);

        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isRetryable(), 'A failed save must retry');
        $this->assertStringContainsString('Deadlock', (string)$result->getError());
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

    private function createRepository(object $customer)
    {
        return new class($customer) implements CustomerRepositoryInterface {
            public int $saveCalls = 0;
            public ?\Throwable $throwOnSave = null;
            public function __construct(
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
                return $customer;
            }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
    }
}
