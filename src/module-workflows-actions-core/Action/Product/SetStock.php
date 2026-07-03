<?php
declare(strict_types=1);

namespace MageOS\WorkflowsActionsCore\Action\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * product.set_stock — without source_code, writes to the DEFAULT SOURCE via
 * the legacy StockRegistryInterface. With source_code, writes an MSI source
 * item via SourceItemsSave (docs/07-actions.md "MSI source-aware").
 *
 * NOTE ON OBJECT MANAGER USE: the MSI path resolves
 * Magento\InventoryApi\Api\* through ObjectManagerInterface AT RUNTIME,
 * deliberately. MSI modules are optional (suggest, not require) and
 * constructor-injecting their interfaces would make this class — and with it
 * the whole bundled action pool — unloadable on installs where MSI is
 * removed. interface_exists() gates the path; a missing MSI install is a
 * terminal, clearly-worded failure rather than a fatal.
 */
class SetStock extends AbstractAction implements SimulateableActionInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_stock';
    }

    public function getLabel(): string
    {
        return (string)__('Set Stock Status/Qty');
    }

    public function getGroup(): string
    {
        return (string)__('Catalog');
    }

    public function getApplicableEntities(): array
    {
        return ['catalog_product'];
    }

    public function getConfigForm(): array
    {
        return [
            ['name' => 'qty', 'label' => 'Quantity', 'type' => 'text', 'required' => false,
                'notice' => 'Leave empty to keep the current quantity (legacy path only).'],
            ['name' => 'is_in_stock', 'label' => 'In Stock', 'type' => 'boolean', 'required' => false],
            ['name' => 'source_code', 'label' => 'MSI Source Code', 'type' => 'text', 'required' => false,
                'notice' => 'Optional. Writes the given MSI source instead of legacy default-source stock. '
                    . 'Requires MSI (Magento_InventoryApi) and a quantity.'],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $qty = $this->stringConfig($config, 'qty');
        $hasStockStatus = array_key_exists('is_in_stock', $config) && $config['is_in_stock'] !== '';
        if ($qty === null && !$hasStockStatus) {
            return ActionResult::failure((string)__('Configure at least one of "qty" or "is_in_stock"'));
        }
        if ($qty !== null && !is_numeric($qty)) {
            return ActionResult::failure((string)__('Invalid qty "%1"', $qty));
        }

        try {
            $product = $this->productRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Product %1 not found', $ctx->getEntityId()));
        }

        $sourceCode = $this->stringConfig($config, 'source_code');
        if ($sourceCode !== null) {
            return $this->executeMsi($product, $sourceCode, $qty, $hasStockStatus, $config);
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

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $qty = $this->stringConfig($config, 'qty');
        $hasStockStatus = array_key_exists('is_in_stock', $config) && $config['is_in_stock'] !== '';
        if ($qty === null && !$hasStockStatus) {
            return ActionResult::failure((string)__('Configure at least one of "qty" or "is_in_stock"'));
        }

        $sourceCode = $this->stringConfig($config, 'source_code');
        if ($sourceCode !== null) {
            if (!interface_exists(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)) {
                return ActionResult::failure(
                    (string)__('MSI is not installed; omit source_code to use legacy stock')
                );
            }
            if ($qty === null) {
                return ActionResult::failure((string)__('"qty" is required when "source_code" is set'));
            }
        }

        $parts = [];
        if ($qty !== null) {
            $parts[] = sprintf('qty=%s', $qty);
        }
        if ($hasStockStatus) {
            $parts[] = sprintf('is_in_stock=%s', $this->boolConfig($config, 'is_in_stock') ? 'true' : 'false');
        }
        return $this->simulated(sprintf(
            'Set stock on product %d (%s): %s',
            $ctx->getEntityId(),
            $sourceCode !== null ? sprintf('MSI source "%s"', $sourceCode) : 'default source',
            implode(', ', $parts)
        ));
    }

    /**
     * MSI path: build and save one source item. Interfaces are resolved via
     * the object manager on purpose — see the class docblock.
     */
    private function executeMsi(
        ProductInterface $product,
        string $sourceCode,
        ?string $qty,
        bool $hasStockStatus,
        array $config
    ): ActionResultInterface {
        if (!interface_exists(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)) {
            return ActionResult::failure(
                (string)__('MSI is not installed; omit source_code to use legacy stock')
            );
        }
        if ($qty === null) {
            // A source item is a full row; there is no partial "status only" write
            return ActionResult::failure((string)__('"qty" is required when "source_code" is set'));
        }

        $inStock = $hasStockStatus ? $this->boolConfig($config, 'is_in_stock') : ((float)$qty > 0);

        try {
            $factory = $this->objectManager->create(
                \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class
            );
            $sourceItem = $factory->create();
            $sourceItem->setSku((string)$product->getSku());
            $sourceItem->setSourceCode($sourceCode);
            $sourceItem->setQuantity((float)$qty);
            // 1/0 literals: SourceItemInterface::STATUS_* constants cannot be
            // referenced without fataling on installs where MSI is absent
            $sourceItem->setStatus($inStock ? 1 : 0);

            $this->objectManager
                ->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)
                ->execute([$sourceItem]);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update source stock: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'sku' => (string)$product->getSku(),
            'source_code' => $sourceCode,
            'qty' => (float)$qty,
            'is_in_stock' => $inStock,
        ]);
    }
}
