<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Quote;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\ConditionLeafPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * "Cart items" subtree (QTE-C1) — the direct clone of Order\ItemsFound over
 * quote items: an item is FOUND / NOT FOUND in the cart where ALL/ANY of the
 * child product conditions hold. Each item's data is wrapped in a DataObject
 * and validated by the child product conditions; item-level snapshot misses
 * (real product EAV attributes) hydrate the item's product via the provider.
 *
 * This unblocks the flagship "abandoned cart contains SKU/brand X" recipe.
 *
 * Items come from the trigger snapshot's `items` array when present (the
 * quote.abandoned payload and QuoteHydrator both emit it), otherwise from the
 * hydrated quote.
 */
class ItemsFound extends AbstractWorkflowCombine
{
    public function __construct(
        Context $context,
        private readonly ConditionLeafPool $leafPool,
        private readonly DataObjectFactory $dataObjectFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * @return $this
     */
    public function loadValueOptions()
    {
        $this->setValueOption([1 => __('FOUND'), 0 => __('NOT FOUND')]);
        return $this;
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        return 'select';
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        // The product leaf arrives through the engine's ConditionLeafPool so
        // this pack never compile-references the catalog pack; without the
        // catalog pack, item children by product attribute are simply not
        // offered (item conditions on the raw item fields still work).
        $productCondition = $this->leafPool->createLeaf(HydrationProviderInterface::TYPE_PRODUCT);
        if ($productCondition === null) {
            return parent::getNewChildSelectOptions();
        }
        $leafClass = get_class($productCondition);
        $attributeOptions = [];
        foreach ($productCondition->loadAttributeOptions()->getAttributeOption() as $code => $label) {
            $attributeOptions[] = ['value' => $leafClass . '|' . $code, 'label' => $label];
        }
        return array_merge_recursive(
            parent::getNewChildSelectOptions(),
            [
                ['label' => __('Product Attribute'), 'value' => $attributeOptions],
            ]
        );
    }

    /**
     * True when (value = FOUND) at least one item matches the ALL/ANY
     * aggregation of child conditions, or (value = NOT FOUND) none does
     */
    public function validate(DataObject $model): bool
    {
        $expected = $this->getValue() === null ? true : (bool)$this->getValue();
        $matched = false;
        foreach ($this->resolveItems($model) as $itemData) {
            if (!is_array($itemData)) {
                continue;
            }
            if ($this->validateItem($this->wrapItem($model, $itemData))) {
                $matched = true;
                break;
            }
        }
        return $matched === $expected;
    }

    /**
     * Aggregate child conditions against a single wrapped item (empty
     * child list matches every item, mirroring core combine semantics)
     */
    private function validateItem(DataObject $item): bool
    {
        $conditions = $this->getConditions();
        if (!is_array($conditions) || $conditions === []) {
            return true;
        }
        $all = $this->getAggregator() === 'all';
        foreach ($conditions as $condition) {
            $validated = (bool)$condition->validate($item);
            if ($all && !$validated) {
                return false;
            }
            if (!$all && $validated) {
                return true;
            }
        }
        return $all;
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveItems(DataObject $model): array
    {
        $items = $model->getData('items');
        if (!is_array($items)) {
            $quote = $this->hydrateContextEntity($model);
            $items = $quote?->getData('items');
        }
        return is_array($items) ? array_values($items) : [];
    }

    /**
     * Wrap item data so child product conditions can hydrate the full
     * product on snapshot miss (entity coordinates: catalog_product/product_id)
     */
    private function wrapItem(DataObject $model, array $itemData): DataObject
    {
        $item = $this->dataObjectFactory->create(['data' => $itemData]);
        $this->propagateHydrationKeys(
            $model,
            $item,
            HydrationProviderInterface::TYPE_PRODUCT,
            (int)($itemData['product_id'] ?? 0)
        );
        return $item;
    }
}
