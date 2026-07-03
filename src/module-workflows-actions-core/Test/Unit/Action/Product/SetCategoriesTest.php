<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Unit\Action\Product;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\Product\SetCategories;
use PHPUnit\Framework\TestCase;

class SetCategoriesTest extends TestCase
{
    public function testExecuteWithMissingCategoryIdsFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('category_ids', $result->getError());
    }

    public function testExecuteWithNonNumericCategoryIdFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['category_ids' => '12,abc,34']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid category id', $result->getError());
    }

    public function testExecuteWithNegativeCategoryIdFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['category_ids' => '12,-5']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testExecuteWithZeroCategoryIdFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['category_ids' => '12,0,34']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
    }

    public function testExecuteWithInvalidModeFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->execute($ctx, ['category_ids' => '12,34', 'mode' => 'invalid']);

        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('Invalid mode', $result->getError());
    }

    public function testSimulateWithMissingCategoryIdsFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->simulate($ctx, []);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('category_ids', $result->getError());
    }

    public function testSimulateWithNonNumericCategoryIdFails(): void
    {
        $productRepo = $this->createProductRepositoryStub();
        $categoryLinkMgmt = $this->createCategoryLinkManagementStub();

        $action = new SetCategories($productRepo, $categoryLinkMgmt);
        $ctx = new ExecutionContext(new WorkflowExecutionStub(entityId: 1));

        $result = $action->simulate($ctx, ['category_ids' => '12,abc,34']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Invalid category id', $result->getError());
    }

    private function createProductRepositoryStub(): ProductRepositoryInterface
    {
        return new class implements ProductRepositoryInterface {
            public function getById(int $productId) { throw new \RuntimeException('Should not be called'); }
        };
    }

    private function createCategoryLinkManagementStub(): CategoryLinkManagementInterface
    {
        return new class implements CategoryLinkManagementInterface {
            public function assignProductToCategories(string $sku, array $categoryIds) { throw new \RuntimeException('Should not be called'); }
        };
    }
}
