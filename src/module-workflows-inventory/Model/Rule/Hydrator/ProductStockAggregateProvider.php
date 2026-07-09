<?php
declare(strict_types=1);

namespace MageOS\WorkflowsInventory\Model\Rule\Hydrator;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\ObjectManagerInterface;
use MageOS\Workflows\Model\Rule\AggregateProviderInterface;

/**
 * Product stock condition leaves (PRD-C1), contributed to the catalog_product
 * root through AggregateProviderPool (E2) under entity type 'catalog_product'.
 * The provider lives in the inventory pack (it owns the queried stock data);
 * the catalog pack's product root reads it only through the engine pool, so
 * neither pack requires the other. When this pack is absent the leaves simply
 * are not offered.
 *
 *   - qty          (numeric)  legacy managed quantity;
 *   - is_in_stock  (boolean)  legacy stock status;
 *   - salable_qty  (numeric)  MSI-aware salable quantity.
 *
 * Data routes:
 *
 *   - qty / is_in_stock come from the legacy CatalogInventory stock item via
 *     StockRegistryInterface — the same API product.set_stock writes, so the
 *     condition reads exactly what the action wrote. Available on every store
 *     (CatalogInventory is a hard require of this pack).
 *
 *   - salable_qty comes from MSI's GetProductSalableQtyInterface, resolved ONLY
 *     behind an interface_exists() runtime guard (the product.set_stock style,
 *     @workflows-dependency-allowlist below). MSI is a genuinely removable
 *     package family, so its interfaces are never type-hinted or DI-wired here;
 *     they are looked up through the object manager at call time. When MSI is
 *     absent, salable_qty is simply ABSENT from the aggregates (not null-set) —
 *     the same fail-toward-false contract the customer aggregates document:
 *     an absent attribute only matches the negative operators, so a workflow
 *     authored against salable_qty degrades to negative-operators-only rather
 *     than fataling or silently matching.
 *
 * Stock-id resolution for salable_qty (documented limitation): MSI salable qty
 * is per (sku, stock), but a condition hydration has only a product id in
 * scope — no website/scope context. We resolve the DEFAULT stock via
 * DefaultStockProviderInterface and report salable_qty for it. On a
 * single-stock store (the default MSI topology and every non-MSI store) this is
 * exact. On a multi-stock / multi-website MSI store it evaluates the default
 * stock only; salable_qty for a non-default stock is out of scope for v1
 * (there is no per-scope condition-evaluation seam yet). This is the honest,
 * pragmatic choice: correct for the common case, explicit about the edge.
 *
 * These attributes never appear in a trigger snapshot, so conditions on them
 * always classify as needs_hydration and resolve in phase 2 (merged onto the
 * hydrated product by the catalog pack's ProductHydrator through the pool).
 *
 * @workflows-dependency-allowlist Magento\InventorySalesApi
 * @workflows-dependency-allowlist Magento\InventoryCatalogApi
 */
class ProductStockAggregateProvider implements AggregateProviderInterface
{
    /**
     * MSI interfaces, referenced as strings only (::class is a compile-time
     * literal that never autoloads) so this class stays loadable where MSI is
     * removed. Resolved through the object manager behind interface_exists().
     */
    private const SALABLE_QTY_INTERFACE = \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class;
    private const DEFAULT_STOCK_INTERFACE = \Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface::class;

    /**
     * Attribute code => [label, workflow input type]. salable_qty is always
     * advertised (metadata is MSI-independent); its VALUE is only present when
     * MSI is installed (see getAggregates()).
     */
    private const ATTRIBUTE_METADATA = [
        'qty' => ['label' => 'Stock: Quantity', 'input_type' => 'numeric'],
        'is_in_stock' => ['label' => 'Stock: In Stock', 'input_type' => 'boolean'],
        'salable_qty' => ['label' => 'Stock: Salable Quantity (MSI)', 'input_type' => 'numeric'],
    ];

    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @return array<string, array{label: string, input_type: string}>
     */
    public function getAttributeMetadata(): array
    {
        return self::ATTRIBUTE_METADATA;
    }

    /**
     * @return array{qty?: float, is_in_stock?: bool, salable_qty?: float}
     */
    public function getAggregates(int $productId): array
    {
        $aggregates = [];

        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
            $aggregates['qty'] = (float) $stockItem->getQty();
            $aggregates['is_in_stock'] = (bool) $stockItem->getIsInStock();
        } catch (\Exception $e) {
            // No legacy stock item (or the registry is unavailable): omit both
            // so they fail toward false instead of reporting a misleading zero.
            unset($e);
        }

        $salableQty = $this->resolveSalableQty($productId);
        if ($salableQty !== null) {
            $aggregates['salable_qty'] = $salableQty;
        }

        return $aggregates;
    }

    /**
     * MSI salable qty for the default stock, or null when MSI is absent or the
     * lookup fails (absent => fail-toward-false). Interfaces resolved through
     * the object manager on purpose — see the class docblock.
     */
    private function resolveSalableQty(int $productId): ?float
    {
        if (!interface_exists(self::SALABLE_QTY_INTERFACE)) {
            return null;
        }

        try {
            $sku = (string) $this->productRepository->getById($productId)->getSku();
            $stockId = (int) $this->objectManager->get(self::DEFAULT_STOCK_INTERFACE)->getId();

            return (float) $this->objectManager->get(self::SALABLE_QTY_INTERFACE)->execute($sku, $stockId);
        } catch (\Throwable $e) {
            // Missing sku, not-salable product, or an MSI edge: treat as absent.
            unset($e);
            return null;
        }
    }
}
