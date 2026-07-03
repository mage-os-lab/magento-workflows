<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\Workflows\Model\Execution\ExecutionContext;
use MageOS\WorkflowsActionsCore\Action\AbstractAction;

/**
 * product.set_stock — v1 writes to the DEFAULT SOURCE ONLY via the legacy
 * StockRegistryInterface, per spec. MSI-aware source/stock selection
 * (docs/07-actions.md "MSI source-aware") is Phase 2: this action will grow
 * optional source_code config backed by the MSI SourceItemsSave API while
 * keeping the legacy path as fallback for non-MSI installs.
 */
class SetStock extends AbstractAction implements SimulateableActionInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_stock';
    }

    public function getLabel(): string
    {
        return 'Set Stock Status/Qty';
    }

    public function getGroup(): string
    {
        return 'Catalog';
    }

    public function getApplicableEntities(): array
    {
        return ['catalog_product'];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'qty', 'label' => 'Quantity', 'type' => 'text', 'required' => false,
                'notice' => 'Leave empty to keep the current quantity.'],
            ['name' => 'is_in_stock', 'label' => 'In Stock', 'type' => 'boolean', 'required' => false],
        ];
    }

    public function execute(ExecutionContext $ctx, array $config): ActionResult
    {
        $qty = $this->stringConfig($config, 'qty');
        $hasStockStatus = array_key_exists('is_in_stock', $config) && $config['is_in_stock'] !== '';
        if ($qty === null && !$hasStockStatus) {
            return ActionResult::failure('Configure at least one of "qty" or "is_in_stock"');
        }
        if ($qty !== null && !is_numeric($qty)) {
            return ActionResult::failure(sprintf('Invalid qty "%s"', $qty));
        }

        try {
            $product = $this->productRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure(sprintf('Product %d not found', $ctx->getEntityId()));
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem($ctx->getEntityId());
            if ($qty !== null) {
                $stockItem->setQty((float)$qty);
            }
            if ($hasStockStatus) {
                $stockItem->setIsInStock($this->boolConfig($config, 'is_in_stock'));
            }
            $this->stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update stock: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'sku' => (string)$product->getSku(),
            'qty' => $qty !== null ? (float)$qty : (float)$stockItem->getQty(),
            'is_in_stock' => (bool)$stockItem->getIsInStock(),
        ]);
    }

    public function simulate(ExecutionContext $ctx, array $config): ActionResult
    {
        $qty = $this->stringConfig($config, 'qty');
        $hasStockStatus = array_key_exists('is_in_stock', $config) && $config['is_in_stock'] !== '';
        if ($qty === null && !$hasStockStatus) {
            return ActionResult::failure('Configure at least one of "qty" or "is_in_stock"');
        }
        $parts = [];
        if ($qty !== null) {
            $parts[] = sprintf('qty=%s', $qty);
        }
        if ($hasStockStatus) {
            $parts[] = sprintf('is_in_stock=%s', $this->boolConfig($config, 'is_in_stock') ? 'true' : 'false');
        }
        return $this->simulated(sprintf(
            'Set stock on product %d (default source): %s',
            $ctx->getEntityId(),
            implode(', ', $parts)
        ));
    }
}
