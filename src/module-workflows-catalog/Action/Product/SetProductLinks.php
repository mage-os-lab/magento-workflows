<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Action\Product;

use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\ProductLinkManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Workflows\Api\ActionResultInterface;
use MageOS\Workflows\Api\ExecutionContextInterface;
use MageOS\Workflows\Api\SimulateableActionInterface;
use MageOS\Workflows\Model\Action\AbstractAction;
use MageOS\Workflows\Model\Action\ActionResult;

/**
 * product.set_product_links — adds to, removes from, or replaces the product's
 * related / cross-sell / up-sell links for ONE link type (PRD-A2). Set
 * semantics mirror product.set_categories: add/remove compute the target set
 * from the CURRENT links of that type (idempotent under redelivery); replace
 * overwrites that type.
 *
 * Via ProductLinkManagementInterface + ProductLinkInterfaceFactory. Because
 * setProductLinks replaces the product's WHOLE link collection, the existing
 * links of the OTHER two types are read back and re-submitted unchanged so a
 * related-links edit can't wipe cross-sells/up-sells.
 *
 * Guards (lean skip-and-report, documented): a target sku that does not exist
 * is SKIPPED with a warning recorded in the action output (not a hard failure)
 * — one bad sku in a batch must not fail the whole step; a self-link (target ==
 * this product's sku) is likewise skipped. When nothing changes and no target
 * was skipped, the action is a no-op (skipped result).
 */
class SetProductLinks extends AbstractAction implements SimulateableActionInterface
{
    private const MODE_ADD = 'add';
    private const MODE_REMOVE = 'remove';
    private const MODE_REPLACE = 'replace';

    private const LINK_TYPES = ['related', 'crosssell', 'upsell'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductLinkManagementInterface $linkManagement,
        private readonly ProductLinkInterfaceFactory $linkFactory
    ) {
    }

    public function getCode(): string
    {
        return 'product.set_product_links';
    }

    public function getLabel(): string
    {
        return (string)__('Set Product Links');
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
            ['name' => 'link_type', 'label' => 'Link Type', 'type' => 'select', 'required' => true,
                'default' => 'related',
                'options' => [
                    ['value' => 'related', 'label' => 'Related Products'],
                    ['value' => 'crosssell', 'label' => 'Cross-sells'],
                    ['value' => 'upsell', 'label' => 'Up-sells'],
                ]],
            ['name' => 'skus', 'label' => 'Target SKUs', 'type' => 'text', 'required' => true,
                'notice' => 'Comma-separated SKUs; variables are interpolated. Missing SKUs are skipped with a warning.'],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => false,
                'default' => self::MODE_ADD,
                'options' => [
                    ['value' => self::MODE_ADD, 'label' => 'Add to current links'],
                    ['value' => self::MODE_REMOVE, 'label' => 'Remove from current links'],
                    ['value' => self::MODE_REPLACE, 'label' => 'Replace all links of this type'],
                ]],
        ];
    }

    public function execute(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $linkType = $this->validateLinkType($config);
        if ($linkType instanceof ActionResult) {
            return $linkType;
        }
        $mode = $this->validateMode($config);
        if ($mode instanceof ActionResult) {
            return $mode;
        }
        $targets = $this->parseSkus($config);
        if ($targets instanceof ActionResult) {
            return $targets;
        }

        try {
            $product = $this->productRepository->getById($ctx->getEntityId());
        } catch (NoSuchEntityException $e) {
            return ActionResult::failure((string)__('Product %1 not found', $ctx->getEntityId()));
        }
        $ownSku = (string)$product->getSku();

        // Validate + filter target skus (skip-and-report).
        $warnings = [];
        $validTargets = [];
        foreach ($targets as $sku) {
            if (strcasecmp($sku, $ownSku) === 0) {
                $warnings[] = sprintf('Skipped self-link "%s"', $sku);
                continue;
            }
            try {
                $this->productRepository->get($sku);
            } catch (NoSuchEntityException $e) {
                $warnings[] = sprintf('Skipped missing SKU "%s"', $sku);
                continue;
            }
            $validTargets[] = $sku;
        }
        $validTargets = array_values(array_unique($validTargets));

        $current = $this->currentLinkedSkus($ownSku, $linkType);
        $target = match ($mode) {
            self::MODE_ADD => array_values(array_unique(array_merge($current, $validTargets))),
            self::MODE_REMOVE => array_values($this->diffCaseInsensitive($current, $validTargets)),
            self::MODE_REPLACE => $validTargets,
        };

        if ($this->sameSet($current, $target)) {
            if ($warnings === []) {
                return ActionResult::skipped(sprintf('No %s link changes for product %s', $linkType, $ownSku));
            }
            // Nothing to write, but surface what was skipped.
            return ActionResult::success([
                'link_type' => $linkType,
                'mode' => $mode,
                'linked_skus' => $target,
                'warnings' => $warnings,
            ]);
        }

        try {
            $this->linkManagement->setProductLinks($ownSku, $this->buildLinks($ownSku, $linkType, $target));
        } catch (\Exception $e) {
            return ActionResult::failure('Could not update product links: ' . $e->getMessage(), true);
        }

        return ActionResult::success([
            'link_type' => $linkType,
            'mode' => $mode,
            'linked_skus' => $target,
            'warnings' => $warnings,
        ]);
    }

    public function simulate(ExecutionContextInterface $ctx, array $config): ActionResultInterface
    {
        $linkType = $this->validateLinkType($config);
        if ($linkType instanceof ActionResult) {
            return $linkType;
        }
        $mode = $this->validateMode($config);
        if ($mode instanceof ActionResult) {
            return $mode;
        }
        $targets = $this->parseSkus($config);
        if ($targets instanceof ActionResult) {
            return $targets;
        }
        return $this->simulated(sprintf(
            '%s %s links [%s] on product %d',
            ucfirst($mode),
            $linkType,
            implode(', ', $targets),
            $ctx->getEntityId()
        ));
    }

    /**
     * @return string[] linked-product skus currently set for $linkType
     */
    private function currentLinkedSkus(string $ownSku, string $linkType): array
    {
        $skus = [];
        foreach ($this->linkManagement->getLinkedItemsByType($ownSku, $linkType) as $link) {
            $skus[] = (string)$link->getLinkedProductSku();
        }
        return array_values(array_unique($skus));
    }

    /**
     * Rebuild the WHOLE link collection: the recomputed $linkType set plus the
     * untouched links of the other two types.
     *
     * @param string[] $target
     * @return ProductLinkInterface[]
     */
    private function buildLinks(string $ownSku, string $linkType, array $target): array
    {
        $links = [];
        $position = 1;
        foreach ($target as $sku) {
            /** @var ProductLinkInterface $link */
            $link = $this->linkFactory->create();
            $link->setSku($ownSku);
            $link->setLinkType($linkType);
            $link->setLinkedProductSku($sku);
            $link->setPosition($position++);
            $links[] = $link;
        }
        foreach (self::LINK_TYPES as $otherType) {
            if ($otherType === $linkType) {
                continue;
            }
            foreach ($this->linkManagement->getLinkedItemsByType($ownSku, $otherType) as $existing) {
                $links[] = $existing;
            }
        }
        return $links;
    }

    /**
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    private function diffCaseInsensitive(array $a, array $b): array
    {
        $lowerB = array_map('strtolower', $b);
        return array_values(array_filter(
            $a,
            static fn (string $sku): bool => !in_array(strtolower($sku), $lowerB, true)
        ));
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function sameSet(array $a, array $b): bool
    {
        $normalize = static function (array $set): array {
            $set = array_map('strtolower', $set);
            sort($set);
            return $set;
        };
        return $normalize($a) === $normalize($b);
    }

    /**
     * @return string[]|ActionResult trimmed non-empty target skus, or a failure
     */
    private function parseSkus(array $config): array|ActionResult
    {
        $raw = $config['skus'] ?? null;
        $pieces = is_array($raw) ? $raw : explode(',', (string)($raw ?? ''));
        $skus = [];
        foreach ($pieces as $piece) {
            $piece = trim((string)$piece);
            if ($piece !== '') {
                $skus[] = $piece;
            }
        }
        if ($skus === []) {
            return $this->missingConfig('skus');
        }
        return array_values(array_unique($skus));
    }

    private function validateLinkType(array $config): string|ActionResult
    {
        $linkType = $this->stringConfig($config, 'link_type');
        if ($linkType === null) {
            return $this->missingConfig('link_type');
        }
        if (!in_array($linkType, self::LINK_TYPES, true)) {
            return ActionResult::failure((string)__('Invalid link type "%1" (related|crosssell|upsell)', $linkType));
        }
        return $linkType;
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
