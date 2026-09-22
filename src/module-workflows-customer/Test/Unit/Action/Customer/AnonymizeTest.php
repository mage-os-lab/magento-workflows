<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Action\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsCustomer\Action\Customer\Anonymize;
use PHPUnit\Framework\TestCase;

/**
 * Confirm-gate coverage for customer.anonymize. Newsletter unsubscription is
 * no longer part of this action (domain-packs S5 moved it to the
 * mage-os/workflows-newsletter plugin), so these tests build the action with
 * only its customer repository and pin that an unconfirmed request never
 * touches the repository.
 */
class AnonymizeTest extends TestCase
{
    private function customerRepositoryThatMustNotBeTouched(): CustomerRepositoryInterface
    {
        return new class implements CustomerRepositoryInterface {
            public function getById($customerId) { throw new \RuntimeException('Should not be called'); }
            public function save($customer, $passwordHash = null) { throw new \RuntimeException('Should not be called'); }
            public function get($email, $websiteId = null) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($customer) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($customerId) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    public function testExecuteWithoutConfirmFails(): void
    {
        $action = new Anonymize($this->customerRepositoryThatMustNotBeTouched());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->execute($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('confirm', $result->getError());
    }

    public function testExecuteWithConfirmFalseFails(): void
    {
        $action = new Anonymize($this->customerRepositoryThatMustNotBeTouched());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->execute($ctx, ['confirm' => false]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('confirm', $result->getError());
    }

    public function testExecuteWithConfirmStringFalseFails(): void
    {
        $action = new Anonymize($this->customerRepositoryThatMustNotBeTouched());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->execute($ctx, ['confirm' => 'false']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testSimulateWithoutConfirmFails(): void
    {
        $action = new Anonymize($this->customerRepositoryThatMustNotBeTouched());
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 123));

        $result = $action->simulate($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('confirm', $result->getError());
    }
}
