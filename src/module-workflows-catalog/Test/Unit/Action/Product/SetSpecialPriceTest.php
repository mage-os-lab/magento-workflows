<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Action\Product;

use Magento\Catalog\Model\Product\Action as ProductAction;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsCatalog\Action\Product\SetSpecialPrice;
use PHPUnit\Framework\TestCase;

class SetSpecialPriceTest extends TestCase
{
    public function testExecuteWithMissingPriceAndNoClearFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('price', $result->getError());
    }

    public function testExecuteWithNegativePriceFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['price' => '-5']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('zero or greater', $result->getError());
    }

    public function testExecuteWithNonNumericPriceFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['price' => 'not-a-number']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid price', $result->getError());
    }

    public function testExecuteWithInvalidFromDateFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['price' => '10', 'from_date' => '2024-13-45']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid date', $result->getError());
    }

    public function testExecuteWithInvalidToDateFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['price' => '10', 'to_date' => 'invalid']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid date', $result->getError());
    }

    public function testExecuteWithFromDateGreaterThanToDateFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, [
            'price' => '10',
            'from_date' => '2024-12-31',
            'to_date' => '2024-12-25'
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('from_date', $result->getError());
        $this->assertStringContainsString('to_date', $result->getError());
    }

    public function testSimulateWithMissingPriceFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->simulate($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('price', $result->getError());
    }

    public function testSimulateWithNegativePriceFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->simulate($ctx, ['price' => '-5']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('zero or greater', $result->getError());
    }

    public function testSimulateWithInvalidFromDateFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->simulate($ctx, ['price' => '10', 'from_date' => 'bad']);

        $this->assertTrue($result->isFailure());
    }

    public function testSimulateWithFromDateGreaterThanToDateFails(): void
    {
        $productAction = $this->createProductActionStub();
        $action = new SetSpecialPrice($productAction);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->simulate($ctx, [
            'price' => '10',
            'from_date' => '2024-12-31',
            'to_date' => '2024-01-01'
        ]);

        $this->assertTrue($result->isFailure());
    }

    private function createProductActionStub(): object
    {
        return new class extends \Magento\Catalog\Model\Product\Action {
            // Bypass AbstractModel's DI constructor; widen updateAttributes to the
            // untyped parent signature (real: updateAttributes($productIds, $attrData, $storeId)).
            public function __construct() {}
            public function updateAttributes($productIds, $attributes, $storeId) { throw new \RuntimeException('Should not be called'); }
        };
    }
}
