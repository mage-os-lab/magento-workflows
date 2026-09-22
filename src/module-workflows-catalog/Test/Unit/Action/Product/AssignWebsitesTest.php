<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Action\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsCatalog\Action\Product\AssignWebsites;
use MageOS\WorkflowsCatalog\Model\Option\WebsiteOptionSource;
use MageOS\WorkflowsCatalog\Test\Unit\Stub\FakeProduct;
use PHPUnit\Framework\TestCase;

/**
 * product.assign_websites (PRD-A1): id parsing/mode guards mirror
 * SetCategories; set semantics (add/remove/replace) converge on the CURRENT
 * membership; an unknown website id is a terminal failure; an already-matching
 * membership is skipped without a save.
 */
class AssignWebsitesTest extends TestCase
{
    private function ctx(int $entityId = 42): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: $entityId));
    }

    public function testMissingWebsiteIdsFails(): void
    {
        $action = new AssignWebsites($this->productRepo(new FakeProduct()), $this->websiteRepo([1, 2]), $this->optionSource());
        $result = $action->execute($this->ctx(), []);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('website_ids', (string)$result->getError());
    }

    public function testNonNumericWebsiteIdFails(): void
    {
        $action = new AssignWebsites($this->productRepo(new FakeProduct()), $this->websiteRepo([1, 2]), $this->optionSource());
        $result = $action->execute($this->ctx(), ['website_ids' => '1,foo']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Invalid website id', (string)$result->getError());
    }

    public function testUnknownWebsiteIdFails(): void
    {
        $action = new AssignWebsites($this->productRepo(new FakeProduct()), $this->websiteRepo([1]), $this->optionSource());
        $result = $action->execute($this->ctx(), ['website_ids' => '1,9', 'mode' => 'add']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Unknown website id', (string)$result->getError());
    }

    public function testAddComputesUnionAndSaves(): void
    {
        $product = new FakeProduct(id: 42, sku: 'HAT-1', websiteIds: [1]);
        $repo = $this->productRepo($product);
        $action = new AssignWebsites($repo, $this->websiteRepo([1, 2]), $this->optionSource());

        $result = $action->execute($this->ctx(), ['website_ids' => '2', 'mode' => 'add']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([1, 2], $result->getOutput()['website_ids']);
        $this->assertSame([1, 2], $product->getWebsiteIds());
        $this->assertSame(1, $repo->saved, 'the whole set is applied in one save');
    }

    public function testRemoveDropsIds(): void
    {
        $product = new FakeProduct(id: 42, sku: 'HAT-1', websiteIds: [1, 2]);
        $action = new AssignWebsites($this->productRepo($product), $this->websiteRepo([1, 2]), $this->optionSource());

        $result = $action->execute($this->ctx(), ['website_ids' => '2', 'mode' => 'remove']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([1], array_values($result->getOutput()['website_ids']));
    }

    public function testReplaceOverwrites(): void
    {
        $product = new FakeProduct(id: 42, sku: 'HAT-1', websiteIds: [1]);
        $action = new AssignWebsites($this->productRepo($product), $this->websiteRepo([1, 2]), $this->optionSource());

        $result = $action->execute($this->ctx(), ['website_ids' => '2', 'mode' => 'replace']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([2], $result->getOutput()['website_ids']);
    }

    public function testAlreadyMatchingMembershipIsSkippedWithoutSave(): void
    {
        $product = new FakeProduct(id: 42, sku: 'HAT-1', websiteIds: [1, 2]);
        $repo = $this->productRepo($product);
        $action = new AssignWebsites($repo, $this->websiteRepo([1, 2]), $this->optionSource());

        $result = $action->execute($this->ctx(), ['website_ids' => '1,2', 'mode' => 'add']);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $repo->saved);
    }

    public function testProductNotFoundFails(): void
    {
        $action = new AssignWebsites($this->productRepo(null), $this->websiteRepo([1]), $this->optionSource());
        $result = $action->execute($this->ctx(), ['website_ids' => '1', 'mode' => 'add']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('not found', (string)$result->getError());
    }

    private function optionSource(): WebsiteOptionSource
    {
        return new WebsiteOptionSource($this->websiteRepo([]));
    }

    private function productRepo(?FakeProduct $product): ProductRepositoryInterface
    {
        return new class ($product) implements ProductRepositoryInterface {
            public int $saved = 0;
            public function __construct(private ?FakeProduct $product)
            {
            }
            public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
            {
                if ($this->product === null) {
                    throw new NoSuchEntityException();
                }
                return $this->product;
            }
            public function save($product, $saveOptions = false)
            {
                $this->saved++;
                return $product;
            }
            public function get($sku, $editMode = false, $storeId = null, $forceReload = false) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($product) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($sku) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    /**
     * @param int[] $knownIds
     */
    private function websiteRepo(array $knownIds): WebsiteRepositoryInterface
    {
        return new class ($knownIds) implements WebsiteRepositoryInterface {
            /** @param int[] $knownIds */
            public function __construct(private array $knownIds)
            {
            }
            public function get($code) { throw new \BadMethodCallException(__METHOD__); }
            public function getById($id)
            {
                if (!in_array((int)$id, $this->knownIds, true)) {
                    throw new NoSuchEntityException();
                }
                return new \stdClass();
            }
            public function getList() { return []; }
            public function getDefault() { throw new \BadMethodCallException(__METHOD__); }
            public function clean() { }
        };
    }
}
