<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Test\Unit\Action\Product;

use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub;
use MageOS\WorkflowsCatalog\Action\Product\SetProductLinks;
use MageOS\WorkflowsCatalog\Test\Unit\Stub\FakeProduct;
use PHPUnit\Framework\TestCase;

/**
 * product.set_product_links (PRD-A2): link-type/mode guards; set semantics per
 * link type; missing/self target skus are skipped with a warning (not a hard
 * failure); other link types survive an edit; a no-change no-warning run is a
 * no-op skip.
 */
class SetProductLinksTest extends TestCase
{
    public const OWN_SKU = 'HAT-1';

    private function ctx(): ExecutionContext
    {
        return new ExecutionContext(new WorkflowExecutionStub(entityId: 42));
    }

    public function testMissingSkusFails(): void
    {
        $action = $this->action([], ['A', 'B']);
        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'add']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('skus', (string)$result->getError());
    }

    public function testInvalidLinkTypeFails(): void
    {
        $action = $this->action([], ['A']);
        $result = $action->execute($this->ctx(), ['link_type' => 'bogus', 'skus' => 'A']);
        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Invalid link type', (string)$result->getError());
    }

    public function testAddUnionAndPreservesOtherTypes(): void
    {
        $mgmt = $this->linkMgmt(['related' => ['A'], 'crosssell' => ['X']]);
        $action = $this->actionWith($mgmt, ['A', 'B', 'X']);

        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'add', 'skus' => 'B']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['A', 'B'], $result->getOutput()['linked_skus']);
        $this->assertSame(['A', 'B'], $this->skusOfType($mgmt->lastItems, 'related'));
        $this->assertSame(['X'], $this->skusOfType($mgmt->lastItems, 'crosssell'), 'cross-sells must survive a related edit');
    }

    public function testReplaceOverwritesType(): void
    {
        $mgmt = $this->linkMgmt(['related' => ['A']]);
        $action = $this->actionWith($mgmt, ['A', 'B']);

        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'replace', 'skus' => 'B']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['B'], $this->skusOfType($mgmt->lastItems, 'related'));
    }

    public function testRemoveDropsTargets(): void
    {
        $mgmt = $this->linkMgmt(['related' => ['A', 'B']]);
        $action = $this->actionWith($mgmt, ['A', 'B']);

        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'remove', 'skus' => 'B']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['A'], $this->skusOfType($mgmt->lastItems, 'related'));
    }

    public function testMissingTargetSkuIsSkippedWithWarning(): void
    {
        $mgmt = $this->linkMgmt(['related' => ['A']]);
        $action = $this->actionWith($mgmt, ['A', 'B']); // GHOST not known

        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'add', 'skus' => 'B,GHOST']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['A', 'B'], $this->skusOfType($mgmt->lastItems, 'related'));
        $this->assertStringContainsString('GHOST', implode(' ', $result->getOutput()['warnings']));
    }

    public function testSelfLinkIsSkipped(): void
    {
        $mgmt = $this->linkMgmt(['related' => ['A']]);
        $action = $this->actionWith($mgmt, ['A']);

        // Only the self-sku targeted: no change, but a warning is reported and
        // setProductLinks is not called.
        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'add', 'skus' => self::OWN_SKU]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $mgmt->setCalls);
        $this->assertStringContainsString(self::OWN_SKU, implode(' ', $result->getOutput()['warnings']));
    }

    public function testNoChangeNoWarningIsSkipped(): void
    {
        $mgmt = $this->linkMgmt(['related' => ['A']]);
        $action = $this->actionWith($mgmt, ['A']);

        $result = $action->execute($this->ctx(), ['link_type' => 'related', 'mode' => 'add', 'skus' => 'A']);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame(0, $mgmt->setCalls);
    }

    public function testArraySkusAreAccepted(): void
    {
        $mgmt = $this->linkMgmt(['upsell' => []]);
        $action = $this->actionWith($mgmt, ['A', 'B']);

        $result = $action->execute($this->ctx(), ['link_type' => 'upsell', 'mode' => 'add', 'skus' => ['A', 'B']]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['A', 'B'], $this->skusOfType($mgmt->lastItems, 'upsell'));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, string[]> $linksByType
     * @param string[] $knownSkus target skus that exist
     */
    private function action(array $linksByType, array $knownSkus): SetProductLinks
    {
        return $this->actionWith($this->linkMgmt($linksByType), $knownSkus);
    }

    /**
     * @param string[] $knownSkus
     */
    private function actionWith(object $mgmt, array $knownSkus): SetProductLinks
    {
        return new SetProductLinks($this->productRepo($knownSkus), $mgmt, $this->linkFactory());
    }

    /**
     * ProductLinkInterfaceFactory has no source file on a real install — it is a
     * GENERATED factory, and the unit-test framework generates it with
     * Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator, whose
     * create(array $data = []) has an EMPTY body. So `new
     * ProductLinkInterfaceFactory()` constructs fine and every create() hands
     * back NULL there ("Call to a member function setSku() on null"), while the
     * standalone runner's shim quietly returns a working data bag.
     *
     * Hence an explicit double. Its link objects implement the FULL real
     * ProductLinkInterface — including the linked-product-type and
     * extension-attribute pairs the action never touches — because a partial
     * implementation is a fatal on a real install; the extension-attribute
     * parameter type is repeated verbatim from the real interface, which is all
     * PHP compares (the generated ProductLinkExtensionInterface is never loaded).
     */
    private function linkFactory(): ProductLinkInterfaceFactory
    {
        return new class extends ProductLinkInterfaceFactory {
            public function create(array $data = []): ProductLinkInterface
            {
                return new class implements ProductLinkInterface {
                    /** @var array<string, mixed> */
                    private array $data = [];

                    public function getSku()
                    {
                        return $this->data['sku'] ?? null;
                    }

                    public function setSku($sku)
                    {
                        $this->data['sku'] = $sku;
                        return $this;
                    }

                    public function getLinkType()
                    {
                        return $this->data['link_type'] ?? null;
                    }

                    public function setLinkType($linkType)
                    {
                        $this->data['link_type'] = $linkType;
                        return $this;
                    }

                    public function getLinkedProductSku()
                    {
                        return $this->data['linked_product_sku'] ?? null;
                    }

                    public function setLinkedProductSku($linkedProductSku)
                    {
                        $this->data['linked_product_sku'] = $linkedProductSku;
                        return $this;
                    }

                    public function getLinkedProductType()
                    {
                        return $this->data['linked_product_type'] ?? null;
                    }

                    public function setLinkedProductType($linkedProductType)
                    {
                        $this->data['linked_product_type'] = $linkedProductType;
                        return $this;
                    }

                    public function getPosition()
                    {
                        return $this->data['position'] ?? null;
                    }

                    public function setPosition($position)
                    {
                        $this->data['position'] = $position;
                        return $this;
                    }

                    public function getExtensionAttributes()
                    {
                        return null;
                    }

                    public function setExtensionAttributes(
                        \Magento\Catalog\Api\Data\ProductLinkExtensionInterface $extensionAttributes
                    ) {
                        return $this;
                    }
                };
            }
        };
    }

    /**
     * @param array<string, string[]> $linksByType
     */
    private function linkMgmt(array $linksByType): object
    {
        return new class ($linksByType, $this->linkFactory()) implements ProductLinkManagementInterface {
            public int $setCalls = 0;
            public ?string $lastSku = null;
            /** @var ProductLinkInterface[] */
            public array $lastItems = [];

            /** @param array<string, string[]> $linksByType */
            public function __construct(private array $linksByType, private ProductLinkInterfaceFactory $factory)
            {
            }

            public function getLinkedItemsByType($sku, $type)
            {
                $out = [];
                foreach ($this->linksByType[$type] ?? [] as $linkedSku) {
                    $link = $this->factory->create();
                    $link->setSku($sku);
                    $link->setLinkType($type);
                    $link->setLinkedProductSku($linkedSku);
                    $out[] = $link;
                }
                return $out;
            }

            public function setProductLinks($sku, array $items)
            {
                $this->setCalls++;
                $this->lastSku = $sku;
                $this->lastItems = $items;
                return true;
            }
        };
    }

    /**
     * @param string[] $knownSkus
     */
    private function productRepo(array $knownSkus): ProductRepositoryInterface
    {
        return new class ($knownSkus) implements ProductRepositoryInterface {
            /** @param string[] $knownSkus */
            public function __construct(private array $knownSkus)
            {
            }
            public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
            {
                return new FakeProduct(id: 42, sku: SetProductLinksTest::OWN_SKU);
            }
            public function get($sku, $editMode = false, $storeId = null, $forceReload = false)
            {
                if (!in_array($sku, $this->knownSkus, true)) {
                    throw new NoSuchEntityException();
                }
                return new FakeProduct(id: 99, sku: (string)$sku);
            }
            public function save($product, $saveOptions = false) { throw new \BadMethodCallException(__METHOD__); }
            public function delete($product) { throw new \BadMethodCallException(__METHOD__); }
            public function deleteById($sku) { throw new \BadMethodCallException(__METHOD__); }
            public function getList($searchCriteria) { throw new \BadMethodCallException(__METHOD__); }
        };
    }

    /**
     * @param ProductLinkInterface[] $items
     * @return string[]
     */
    private function skusOfType(array $items, string $type): array
    {
        $skus = [];
        foreach ($items as $item) {
            if ($item->getLinkType() === $type) {
                $skus[] = (string)$item->getLinkedProductSku();
            }
        }
        return $skus;
    }
}
