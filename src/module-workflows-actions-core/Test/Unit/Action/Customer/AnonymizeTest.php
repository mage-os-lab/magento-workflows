<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\Customer\Anonymize;
use PHPUnit\Framework\TestCase;

class AnonymizeTest extends TestCase
{
    public function testExecuteWithoutConfirmFails(): void
    {
        $customerRepo = new class implements CustomerRepositoryInterface {
            public function getById($customerId) { throw new \RuntimeException('Should not be called'); }
            public function save($customer, $passwordHash = null) { throw new \RuntimeException('Should not be called'); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete(\Magento\Customer\Api\Data\CustomerInterface $customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
        $subscriptionManager = new class implements SubscriptionManagerInterface {
            public function unsubscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \RuntimeException('Should not be called'); }
            public function subscribe(string $email, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function unsubscribe(string $email, int $storeId, string $confirmCode): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function subscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
        };

        $action = new Anonymize($customerRepo, $subscriptionManager);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->execute($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('confirm', $result->getError());
    }

    public function testExecuteWithConfirmFalseFails(): void
    {
        $customerRepo = new class implements CustomerRepositoryInterface {
            public function getById($customerId) { throw new \RuntimeException('Should not be called'); }
            public function save($customer, $passwordHash = null) { throw new \RuntimeException('Should not be called'); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete(\Magento\Customer\Api\Data\CustomerInterface $customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
        $subscriptionManager = new class implements SubscriptionManagerInterface {
            public function unsubscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \RuntimeException('Should not be called'); }
            public function subscribe(string $email, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function unsubscribe(string $email, int $storeId, string $confirmCode): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function subscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
        };

        $action = new Anonymize($customerRepo, $subscriptionManager);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->execute($ctx, ['confirm' => false]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('confirm', $result->getError());
    }

    public function testExecuteWithConfirmStringFalseFails(): void
    {
        $customerRepo = new class implements CustomerRepositoryInterface {
            public function getById($customerId) { throw new \RuntimeException('Should not be called'); }
            public function save($customer, $passwordHash = null) { throw new \RuntimeException('Should not be called'); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete(\Magento\Customer\Api\Data\CustomerInterface $customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
        $subscriptionManager = new class implements SubscriptionManagerInterface {
            public function unsubscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \RuntimeException('Should not be called'); }
            public function subscribe(string $email, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function unsubscribe(string $email, int $storeId, string $confirmCode): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function subscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
        };

        $action = new Anonymize($customerRepo, $subscriptionManager);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->execute($ctx, ['confirm' => 'false']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testSimulateWithoutConfirmFails(): void
    {
        $customerRepo = new class implements CustomerRepositoryInterface {
            public function getById($customerId) { throw new \RuntimeException('Should not be called'); }
            public function save($customer, $passwordHash = null) { throw new \RuntimeException('Should not be called'); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList(\Magento\Framework\Api\SearchCriteriaInterface $searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete(\Magento\Customer\Api\Data\CustomerInterface $customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
        $subscriptionManager = new class implements SubscriptionManagerInterface {
            public function unsubscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \RuntimeException('Should not be called'); }
            public function subscribe(string $email, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function unsubscribe(string $email, int $storeId, string $confirmCode): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
            public function subscribeCustomer(int $customerId, int $storeId): \Magento\Newsletter\Model\Subscriber { throw new \BadMethodCallException(__METHOD__); }
        };

        $action = new Anonymize($customerRepo, $subscriptionManager);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->simulate($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('confirm', $result->getError());
    }
}
