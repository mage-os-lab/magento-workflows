<?php
declare(strict_types=1);

namespace MageOS\WorkflowsInventory\Test\Unit\Model\Rule\Hydrator;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use MageOS\WorkflowsInventory\Model\Rule\Hydrator\ProductStockAggregateProvider;
use MageOS\WorkflowsScheduler\Test\Unit\Stub\FakeObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * ProductStockAggregateProvider (PRD-C1): the product stock condition leaves
 * contributed to the catalog_product root.
 *
 * Two data routes are covered here:
 *
 *  - legacy path: qty + is_in_stock read from the CatalogInventory stock item
 *    via StockRegistryInterface; a missing stock item omits both (fail toward
 *    false) rather than reporting a misleading zero;
 *  - guard path (absent MSI): salable_qty is resolved only behind
 *    interface_exists(GetProductSalableQtyInterface). MSI is not installed in
 *    the standalone runner, so every case here exercises the absent-MSI branch:
 *    salable_qty is ABSENT from the aggregates, so a condition on it matches
 *    negative operators only (same fail-toward-false contract the customer
 *    aggregates document). The metadata still advertises salable_qty regardless.
 */
class ProductStockAggregateProviderTest extends TestCase
{
    public function testMetadataAdvertisesTheThreeStockLeavesIndependentOfMsi(): void
    {
        $metadata = $this->provider($this->stockRegistry(null))->getAttributeMetadata();

        $this->assertSame('numeric', $metadata['qty']['input_type']);
        $this->assertSame('boolean', $metadata['is_in_stock']['input_type']);
        // salable_qty is always advertised — its VALUE is MSI-gated, not its
        // metadata.
        $this->assertSame('numeric', $metadata['salable_qty']['input_type']);
    }

    public function testLegacyPathReportsQtyAndStockStatus(): void
    {
        $registry = $this->stockRegistry($this->stockItem(7.0, true));

        $aggregates = $this->provider($registry)->getAggregates(42);

        $this->assertSame(7.0, $aggregates['qty']);
        $this->assertTrue($aggregates['is_in_stock']);
        $this->assertFalse(
            array_key_exists('salable_qty', $aggregates),
            'salable_qty must be absent without MSI (fail-toward-false)'
        );
    }

    public function testOutOfStockZeroQtyIsReportedNotOmitted(): void
    {
        // 0 / out-of-stock are legitimate legacy values, distinct from absent:
        // they must be present so positive operators (e.g. is_in_stock = false)
        // can match.
        $registry = $this->stockRegistry($this->stockItem(0.0, false));

        $aggregates = $this->provider($registry)->getAggregates(42);

        $this->assertSame(0.0, $aggregates['qty']);
        $this->assertFalse($aggregates['is_in_stock']);
    }

    public function testMissingStockItemOmitsLegacyLeaves(): void
    {
        // Registry throws (no stock item / registry unavailable): both legacy
        // leaves are omitted so they fail toward false.
        $registry = $this->stockRegistry(null, true);

        $aggregates = $this->provider($registry)->getAggregates(42);

        $this->assertFalse(array_key_exists('qty', $aggregates));
        $this->assertFalse(array_key_exists('is_in_stock', $aggregates));
        $this->assertFalse(array_key_exists('salable_qty', $aggregates));
        $this->assertSame([], $aggregates);
    }

    public function testSalableQtyAbsentWhenMsiNotInstalled(): void
    {
        // The explicit guard-path assertion: with MSI absent the provider never
        // touches the product repository or object manager for salable qty.
        $registry = $this->stockRegistry($this->stockItem(3.0, true));

        $aggregates = $this->provider($registry)->getAggregates(99);

        $this->assertFalse(array_key_exists('salable_qty', $aggregates));
    }

    // --- doubles ------------------------------------------------------------

    private function provider(StockRegistryInterface $registry): ProductStockAggregateProvider
    {
        return new ProductStockAggregateProvider(
            $registry,
            $this->productRepository(),
            new FakeObjectManager()
        );
    }

    private function stockRegistry(?object $item, bool $throw = false): StockRegistryInterface
    {
        return new class ($item, $throw) implements StockRegistryInterface {
            public function __construct(private ?object $item, private bool $throw)
            {
            }

            public function getStockItem($productId, $scopeId = null)
            {
                if ($this->throw) {
                    throw new \RuntimeException('no stock item for ' . $productId);
                }
                return $this->item;
            }
        };
    }

    private function stockItem(float $qty, bool $inStock): object
    {
        return new class ($qty, $inStock) {
            public function __construct(private float $qty, private bool $inStock)
            {
            }

            public function getQty()
            {
                return $this->qty;
            }

            public function getIsInStock()
            {
                return $this->inStock;
            }
        };
    }

    private function productRepository(): ProductRepositoryInterface
    {
        // Never called without MSI; salable-qty resolution returns before the
        // sku lookup.
        return new class implements ProductRepositoryInterface {
            public function getById($productId, $editMode = false, $storeId = null, $forceReload = false)
            {
                throw new \RuntimeException('getById must not be called when MSI is absent');
            }
        };
    }
}
