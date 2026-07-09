<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Action\Product;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * product.set_categories — adds to, removes from, or replaces the product's
 * category assignments via CategoryLinkManagement. add/remove compute the
 * target set from the CURRENT assignments, so redelivery converges on the
 * same final set (idempotent). An empty result set is allowed for "remove".
 */
class SetCategories extends AbstractAction implements SimulateableActionInterface
{
    private const MODE_ADD = 'add';
    private const MODE_REMOVE = 'remove';
    private const MODE_REPLACE = 'replace';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_categories';
    }

    public function getLabel(): string
    {
        return (string)__('Set Product Categories');
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
            ['name' => 'category_ids', 'label' => 'Category IDs', 'type' => 'text', 'required' => true,
                'notice' => 'Comma-separated category IDs, e.g. 12,15,42.'],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => false,
                'default' => self::MODE_ADD,
                'options' => [
                    ['value' => self::MODE_ADD, 'label' => 'Add to current categories'],
                    ['value' => self::MODE_REMOVE, 'label' => 'Remove from current categories'],
                    ['value' => self::MODE_REPLACE, 'label' => 'Replace all categories'],
                ]],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ids = $this->parseCategoryIds($config);
        if ($ids instanceof ActionResult) {
            return $ids;
        }
        $mode = $this->validateMode($config);
        if ($mode instanceof ActionResult) {
            return $mode;
        }

        try {
            $product = $this->productRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Product %1 not found', $ctx->getEntityId()));
        }

        $current = array_map('intval', (array)$product->getCategoryIds());
        $target = match ($mode) {
            self::MODE_ADD => array_values(array_unique(array_merge($current, $ids))),
            self::MODE_REMOVE => array_values(array_diff($current, $ids)),
            self::MODE_REPLACE => $ids,
        };

        try {
            $this->categoryLinkManagement->assignProductToCategories((string)$product->getSku(), $target);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update product categories: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'sku' => (string)$product->getSku(),
            'mode' => $mode,
            'category_ids' => $target,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ids = $this->parseCategoryIds($config);
        if ($ids instanceof ActionResult) {
            return $ids;
        }
        $mode = $this->validateMode($config);
        if ($mode instanceof ActionResult) {
            return $mode;
        }
        return $this->simulated(sprintf(
            '%s categories [%s] on product %d',
            ucfirst($mode),
            implode(', ', $ids),
            $ctx->getEntityId()
        ));
    }

    /**
     * @return int[]|ActionResult positive category ids, or a terminal failure
     */
    private function parseCategoryIds(array $config): array|ActionResult
    {
        $raw = $this->stringConfig($config, 'category_ids');
        if ($raw === null) {
            return $this->missingConfig('category_ids');
        }
        $ids = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if (!preg_match('/^\d+$/', $piece) || (int)$piece <= 0) {
                return ActionResult::failure((string)__('Invalid category id "%1"', $piece));
            }
            $ids[] = (int)$piece;
        }
        if ($ids === []) {
            return $this->missingConfig('category_ids');
        }
        return array_values(array_unique($ids));
    }

    private function validateMode(array $config): string|ActionResult
    {
        $mode = $this->stringConfig($config, 'mode', self::MODE_ADD) ?? self::MODE_ADD;
        if (!in_array($mode, [self::MODE_ADD, self::MODE_REMOVE, self::MODE_REPLACE], true)) {
            return ActionResult::failure((string)__('Invalid mode "%1" (add|remove|replace)', $mode));
        }
        return $mode;
    }
}
