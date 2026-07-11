<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Test\Integration\Action\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use MageOS\WorkflowsActionsCore\Action\Product\SetAttribute;
use MageOS\WorkflowsActionsCore\Test\Integration\Action\ActionTestCase;

/**
 * Plan #20 (docs/20-integration-test-plan.md §5) — product.set_attribute
 * writes an allowed store-scoped attribute, and REFUSES attributes on the
 * deny-by-default list delivered by the MERGED di.xml (docs/10 deferred
 * privilege escalation). Resolving the action from the object manager is what
 * closes the "permissive [] default" tripwire (docs/19): the denylist must be
 * present because DI merged it, not because a test hand-fed it.
 *
 * @magentoDbIsolation enabled
 * @magentoAppArea adminhtml
 */
class SetAttributeTest extends ActionTestCase
{
    private ProductRepositoryInterface $productRepository;
    private SetAttribute $action;

    protected function setUp(): void
    {
        $this->productRepository = $this->resolve(ProductRepositoryInterface::class);
        $this->action = $this->resolve(SetAttribute::class);
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testWritesAllowedAttributeAtStoreScope(): void
    {
        $product = $this->productRepository->get('simple');
        $ctx = $this->buildContext((int)$product->getId(), 1);

        $result = $this->action->execute($ctx, ['attribute_code' => 'meta_title', 'value' => 'WF Title']);
        $this->assertTrue($result->isSuccess(), $result->getError() ?? '');

        $reloaded = $this->productRepository->get('simple', false, 1, true);
        $this->assertSame('WF Title', (string)$reloaded->getData('meta_title'));
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testDenylistedAttributesAreRefused(): void
    {
        $product = $this->productRepository->get('simple');
        $ctx = $this->buildContext((int)$product->getId(), 1);

        foreach (['sku', 'status'] as $denied) {
            $result = $this->action->execute($ctx, ['attribute_code' => $denied, 'value' => 'x']);
            $this->assertTrue($result->isFailure(), sprintf('"%s" must be refused', $denied));
            $this->assertFalse($result->isRetryable());
            $this->assertStringContainsString('denylist', (string)$result->getError());
        }
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testNonexistentAttributeFails(): void
    {
        $product = $this->productRepository->get('simple');
        $ctx = $this->buildContext((int)$product->getId(), 1);

        $result = $this->action->execute($ctx, ['attribute_code' => 'wf_no_such_attribute', 'value' => 'x']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('does not exist', (string)$result->getError());
    }

    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     */
    public function testInvalidAttributeCodeShapeFails(): void
    {
        $product = $this->productRepository->get('simple');
        $ctx = $this->buildContext((int)$product->getId(), 1);

        $result = $this->action->execute($ctx, ['attribute_code' => 'bad code!', 'value' => 'x']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Invalid attribute code', (string)$result->getError());
    }
}
