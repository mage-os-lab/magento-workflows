<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Integration\Rule\Condition;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsCatalog\Model\Rule\Condition\Product\Attribute as ProductAttribute;
use MageOS\WorkflowsCatalog\Model\Rule\Condition\Product\Combine as ProductCombine;
use MageOS\Workflows\Model\Rule\ConditionEvaluator;
use MageOS\Workflows\Model\WorkflowExecution;
use PHPUnit\Framework\TestCase;

/**
 * Plan #15 (docs/20-integration-test-plan.md §5) — catalog_product condition
 * evaluation against a real product with EAV auto-discovery
 * (docs/06-conditions.md). Empty trigger forces phase-2 hydration via the
 * ProductHydrator, so sku/price comparisons hit the real EAV-complete product.
 *
 * @magentoDbIsolation enabled
 */
class ProductConditionTest extends TestCase
{
    private ConditionEvaluator $evaluator;
    private ProductRepositoryInterface $productRepository;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->evaluator = $objectManager->get(ConditionEvaluator::class);
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testSkuAndPriceEvaluateAgainstTheRealProduct(): void
    {
        $product = $this->productRepository->get('simple');
        $ctx = $this->contextFor((int)$product->getId());
        $price = (float)$product->getPrice();

        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('sku', '==', 'simple')),
                'catalog_product',
                $ctx,
                false
            )
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('sku', '==', 'not-the-sku')),
                'catalog_product',
                $ctx,
                false
            )
        );
        $this->assertTrue(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('price', '>=', (string)$price)),
                'catalog_product',
                $ctx,
                false
            )
        );
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('price', '>', (string)($price + 1))),
                'catalog_product',
                $ctx,
                false
            )
        );
    }

    /**
     * A user-defined promo-rule product attribute auto-discovers into the
     * option set (the CatalogRule pattern generalized).
     *
     * @magentoDataFixture MageOS_Workflows::Test/Integration/Rule/_files/product_promo_attribute.php
     */
    public function testPromoRuleAttributeAutoDiscovers(): void
    {
        /** @var ProductAttribute $attribute */
        $attribute = Bootstrap::getObjectManager()->create(ProductAttribute::class);
        $codes = array_map('strval', array_keys($attribute->loadAttributeOptions()->getAttributeOption()));

        $this->assertContains('wf_promo_flag', $codes, 'Promo-rule product attribute must auto-discover');
        // The fixed special attributes remain available.
        $this->assertContains('sku', $codes);
        $this->assertContains('attribute_set_id', $codes);
        $this->assertContains('category_ids', $codes);
    }

    public function testVanishedProductFailsClosedOnRevalidation(): void
    {
        $ctx = $this->contextFor(0x6d17 /* nonexistent product id */);
        $this->assertFalse(
            $this->evaluator->evaluateSerialized(
                $this->tree($this->leaf('sku', '==', 'simple')),
                'catalog_product',
                $ctx,
                true
            )
        );
    }

    public function testRootCombineResolvesFromMergedPool(): void
    {
        $combine = Bootstrap::getObjectManager()
            ->get(\MageOS\Workflows\Model\Rule\ConditionCombinePool::class)
            ->getCombine('catalog_product');
        $this->assertInstanceOf(ProductCombine::class, $combine);
    }

    private function contextFor(int $productId): ExecutionContext
    {
        /** @var WorkflowExecution $execution */
        $execution = Bootstrap::getObjectManager()->create(WorkflowExecution::class);
        $execution->setUuid('33333333-3333-3333-3333-333333333333');
        $execution->setEntityId($productId);
        $execution->setStoreId(1);
        return new ExecutionContext($execution, [], [], []);
    }

    private function leaf(string $attribute, string $operator, string $value): array
    {
        return [
            'type' => ProductAttribute::class,
            'attribute' => $attribute,
            'operator' => $operator,
            'value' => $value,
        ];
    }

    private function tree(array ...$leaves): string
    {
        return (string)json_encode([
            'type' => ProductCombine::class,
            'aggregator' => 'all',
            'value' => '1',
            'conditions' => array_values($leaves),
        ], JSON_THROW_ON_ERROR);
    }
}
