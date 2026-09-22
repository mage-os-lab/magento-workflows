<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Action\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;
use MageOS\WorkflowsCatalog\Model\Option\WebsiteOptionSource;

/**
 * product.assign_websites — adds to, removes from, or replaces the product's
 * website membership (PRD-A1). Set semantics mirror product.set_categories:
 * add/remove compute the target set from the CURRENT membership so redelivery
 * converges on the same final set (idempotent); replace overwrites.
 *
 * API-pure route: the whole target set is applied in ONE
 * ProductRepositoryInterface::save (product save with website_ids), chosen over
 * per-link ProductWebsiteLinkRepositoryInterface calls because add/remove
 * already need to read the current set off the product and the single save
 * applies the whole set atomically — the same whole-set assignment shape as
 * SetCategories. Website ids are read/written through the concrete product
 * model's get/setWebsiteIds() exactly as ProductHydrator reads them.
 *
 * Guard: every configured website id is validated up front against
 * WebsiteRepositoryInterface; an unknown id is a terminal failure (not a
 * silent skip) so a typo can't quietly drop a website.
 */
class AssignWebsites extends AbstractAction implements SimulateableActionInterface
{
    private const MODE_ADD = 'add';
    private const MODE_REMOVE = 'remove';
    private const MODE_REPLACE = 'replace';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        private readonly WebsiteOptionSource $websiteOptionSource
    ) {
    }

    public function getCode(): string
    {
        return 'product.assign_websites';
    }

    public function getLabel(): string
    {
        return (string)__('Assign Product Websites');
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
        $websiteField = ['name' => 'website_ids', 'label' => 'Websites', 'type' => 'multiselect', 'required' => true,
            'notice' => 'Comma-separated website IDs, e.g. 1,2.'];
        try {
            $options = $this->websiteOptionSource->fetch();
            if ($options !== []) {
                $websiteField['options'] = $options;
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        return [
            $websiteField,
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => false,
                'default' => self::MODE_ADD,
                'options' => [
                    ['value' => self::MODE_ADD, 'label' => 'Add to current websites'],
                    ['value' => self::MODE_REMOVE, 'label' => 'Remove from current websites'],
                    ['value' => self::MODE_REPLACE, 'label' => 'Replace all websites'],
                ]],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ids = $this->parseWebsiteIds($config);
        if ($ids instanceof ActionResult) {
            return $ids;
        }
        $mode = $this->validateMode($config);
        if ($mode instanceof ActionResult) {
            return $mode;
        }

        $unknown = $this->firstUnknownWebsite($ids);
        if ($unknown !== null) {
            return ActionResult::failure((string)__('Unknown website id "%1"', $unknown));
        }

        try {
            $product = $this->productRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Product %1 not found', $ctx->getEntityId()));
        }
        if (!$product instanceof Product) {
            return ActionResult::failure((string)__('Product %1 does not expose website links', $ctx->getEntityId()));
        }

        $current = array_map('intval', (array)$product->getWebsiteIds());
        $target = match ($mode) {
            self::MODE_ADD => array_values(array_unique(array_merge($current, $ids))),
            self::MODE_REMOVE => array_values(array_diff($current, $ids)),
            self::MODE_REPLACE => $ids,
        };

        sort($current);
        $sortedTarget = $target;
        sort($sortedTarget);
        if ($current === $sortedTarget) {
            return ActionResult::skipped((string)__('Product %1 website membership already matches', $ctx->getEntityId()));
        }

        try {
            $product->setWebsiteIds($target);
            $this->productRepository->save($product);
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update product websites: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'sku' => (string)$product->getSku(),
            'mode' => $mode,
            'website_ids' => $target,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $ids = $this->parseWebsiteIds($config);
        if ($ids instanceof ActionResult) {
            return $ids;
        }
        $mode = $this->validateMode($config);
        if ($mode instanceof ActionResult) {
            return $mode;
        }
        $unknown = $this->firstUnknownWebsite($ids);
        if ($unknown !== null) {
            return ActionResult::failure((string)__('Unknown website id "%1"', $unknown));
        }
        return $this->simulated(sprintf(
            '%s websites [%s] on product %d',
            ucfirst($mode),
            implode(', ', $ids),
            $ctx->getEntityId()
        ));
    }

    /**
     * @param int[] $ids
     * @return int|null the first configured id with no website, or null when all exist
     */
    private function firstUnknownWebsite(array $ids): ?int
    {
        foreach ($ids as $id) {
            try {
                $this->websiteRepository->getById($id);
            } catch (NoSuchEntityException $e) {
                return $id;
            }
        }
        return null;
    }

    /**
     * @return int[]|ActionResult positive website ids, or a terminal failure
     */
    private function parseWebsiteIds(array $config): array|ActionResult
    {
        $raw = $this->stringConfig($config, 'website_ids');
        if ($raw === null) {
            return $this->missingConfig('website_ids');
        }
        $ids = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }
            if (!preg_match('/^\d+$/', $piece) || (int)$piece <= 0) {
                return ActionResult::failure((string)__('Invalid website id "%1"', $piece));
            }
            $ids[] = (int)$piece;
        }
        if ($ids === []) {
            return $this->missingConfig('website_ids');
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
