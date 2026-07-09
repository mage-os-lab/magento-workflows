<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Order;

use Magento\Framework\DataObject;
use Magento\Framework\DataObjectFactory;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\WorkflowsCatalog\Model\Rule\Condition\Product\Attribute as ProductAttribute;

/**
 * "Order items" subtree — the SalesRule Found pattern over order items:
 * an item is FOUND / NOT FOUND in the order where ALL/ANY of the child
 * product conditions hold. Each item's data is wrapped in a DataObject and
 * validated by the child product conditions; item-level snapshot misses
 * (real product EAV attributes) hydrate the item's product via the provider.
 *
 * Items come from the trigger snapshot's `items` array when present,
 * otherwise from the hydrated order.
 */
class ItemsFound extends AbstractWorkflowCombine
{
    public function __construct(
        Context $context,
        private readonly ProductAttribute $productCondition,
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
        $attributeOptions = [];
        foreach ($this->productCondition->loadAttributeOptions()->getAttributeOption() as $code => $label) {
            $attributeOptions[] = ['value' => ProductAttribute::class . '|' . $code, 'label' => $label];
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
            $order = $this->hydrateContextEntity($model);
            $items = $order?->getData('items');
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
