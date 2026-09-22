<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action;

use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use PHPUnit\Framework\TestCase;

class AbstractActionTest extends TestCase
{
    private ConcreteAction $action;

    public function setUp(): void
    {
        $this->action = new ConcreteAction();
    }

    public function testStringConfigWithValidString(): void
    {
        $config = ['key' => '  value  '];
        $result = $this->action->publicStringConfig($config, 'key');
        $this->assertSame('value', $result);
    }

    public function testStringConfigWithNull(): void
    {
        $config = [];
        $result = $this->action->publicStringConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testStringConfigWithDefault(): void
    {
        $config = [];
        $result = $this->action->publicStringConfig($config, 'key', 'default');
        $this->assertSame('default', $result);
    }

    public function testStringConfigWithEmptyString(): void
    {
        $config = ['key' => '   '];
        $result = $this->action->publicStringConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testStringConfigWithEmptyStringAndDefault(): void
    {
        $config = ['key' => '   '];
        $result = $this->action->publicStringConfig($config, 'key', 'default');
        $this->assertSame('default', $result);
    }

    public function testStringConfigWithArray(): void
    {
        $config = ['key' => ['nested' => 'value']];
        $result = $this->action->publicStringConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testStringConfigWithObject(): void
    {
        $config = ['key' => new \stdClass()];
        $result = $this->action->publicStringConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testBoolConfigWithTrue(): void
    {
        $config = ['key' => true];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithFalse(): void
    {
        $config = ['key' => false];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithStringTrue(): void
    {
        $config = ['key' => 'true'];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithString1(): void
    {
        $config = ['key' => '1'];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithStringYes(): void
    {
        $config = ['key' => 'yes'];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithStringOn(): void
    {
        $config = ['key' => 'on'];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertTrue($result);
    }

    public function testBoolConfigWithString0(): void
    {
        $config = ['key' => '0'];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithStringNo(): void
    {
        $config = ['key' => 'no'];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithNull(): void
    {
        $config = [];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithNullAndDefault(): void
    {
        $config = [];
        $result = $this->action->publicBoolConfig($config, 'key', true);
        $this->assertTrue($result);
    }

    public function testBoolConfigWithEmptyString(): void
    {
        $config = ['key' => ''];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertFalse($result);
    }

    public function testBoolConfigWithInteger(): void
    {
        $config = ['key' => 1];
        $result = $this->action->publicBoolConfig($config, 'key');
        $this->assertTrue($result);
    }

    public function testIntConfigWithValidInteger(): void
    {
        $config = ['key' => '42'];
        $result = $this->action->publicIntConfig($config, 'key');
        $this->assertSame(42, $result);
    }

    public function testIntConfigWithValidIntegerValue(): void
    {
        $config = ['key' => 42];
        $result = $this->action->publicIntConfig($config, 'key');
        $this->assertSame(42, $result);
    }

    public function testIntConfigWithFloat(): void
    {
        $config = ['key' => '42.5'];
        $result = $this->action->publicIntConfig($config, 'key');
        $this->assertSame(42, $result);
    }

    public function testIntConfigWithNull(): void
    {
        $config = [];
        $result = $this->action->publicIntConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testIntConfigWithDefault(): void
    {
        $config = [];
        $result = $this->action->publicIntConfig($config, 'key', 99);
        $this->assertSame(99, $result);
    }

    public function testIntConfigWithEmptyString(): void
    {
        $config = ['key' => ''];
        $result = $this->action->publicIntConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testIntConfigWithNonNumeric(): void
    {
        $config = ['key' => 'not a number'];
        $result = $this->action->publicIntConfig($config, 'key');
        $this->assertNull($result);
    }

    public function testMissingConfigReturnsFailureWithMessage(): void
    {
        $result = $this->action->publicMissingConfig('test_key');

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('test_key', $result->getError());
    }

    public function testGetAclResourceForOrderAction(): void
    {
        $action = new OrderActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_sales', $aclResource);
    }

    public function testGetAclResourceForCustomerAction(): void
    {
        $action = new CustomerActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_customer', $aclResource);
    }

    public function testGetAclResourceForProductAction(): void
    {
        $action = new ProductActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_catalog', $aclResource);
    }

    public function testGetAclResourceForMarketingAction(): void
    {
        $action = new MarketingActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_marketing', $aclResource);
    }

    public function testGetAclResourceForNotifyAction(): void
    {
        $action = new NotifyActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_notify', $aclResource);
    }

    public function testGetAclResourceForFlowAction(): void
    {
        $action = new FlowActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_flow', $aclResource);
    }

    public function testGetAclResourceForUnknownPrefix(): void
    {
        $action = new UnknownPrefixActionStub();
        $aclResource = $action->getAclResource();
        $this->assertSame('MageOS_Workflows::action_unknown', $aclResource);
    }
}

class ConcreteAction extends AbstractAction
{
    public function getCode(): string
    {
        return 'test.action';
    }

    public function getLabel(): string
    {
        return 'Test Action';
    }

    public function getGroup(): string
    {
        return 'Test';
    }

    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface
    {
        return ActionResult::success();
    }

    public function publicStringConfig(array $config, string $key, ?string $default = null): ?string
    {
        return $this->stringConfig($config, $key, $default);
    }

    public function publicBoolConfig(array $config, string $key, bool $default = false): bool
    {
        return $this->boolConfig($config, $key, $default);
    }

    public function publicIntConfig(array $config, string $key, ?int $default = null): ?int
    {
        return $this->intConfig($config, $key, $default);
    }

    public function publicMissingConfig(string $key): ActionResult
    {
        return $this->missingConfig($key);
    }
}

class OrderActionStub extends AbstractAction
{
    public function getCode(): string { return 'order.test'; }
    public function getLabel(): string { return 'Order Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}

class CustomerActionStub extends AbstractAction
{
    public function getCode(): string { return 'customer.test'; }
    public function getLabel(): string { return 'Customer Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}

class ProductActionStub extends AbstractAction
{
    public function getCode(): string { return 'product.test'; }
    public function getLabel(): string { return 'Product Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}

class MarketingActionStub extends AbstractAction
{
    public function getCode(): string { return 'marketing.test'; }
    public function getLabel(): string { return 'Marketing Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}

class NotifyActionStub extends AbstractAction
{
    public function getCode(): string { return 'notify.test'; }
    public function getLabel(): string { return 'Notify Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}

class FlowActionStub extends AbstractAction
{
    public function getCode(): string { return 'flow.test'; }
    public function getLabel(): string { return 'Flow Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}

class UnknownPrefixActionStub extends AbstractAction
{
    public function getCode(): string { return 'unknown.test'; }
    public function getLabel(): string { return 'Unknown Test'; }
    public function getGroup(): string { return 'Test'; }
    public function execute(\MageOS\Workflows\Api\ExecutionContextInterface $ctx, array $config): \MageOS\Workflows\Api\ActionResultInterface { return ActionResult::success(); }
}
