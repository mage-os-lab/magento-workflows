<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Model\Rule\Condition\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Product attribute condition with EAV introspection (CatalogRule product
 * condition pattern, simplified).
 *
 * loadAttributeOptions() offers every product attribute flagged usable in
 * promo rules or searchable — custom EAV attributes included automatically —
 * plus the special attribute_set_id and category_ids handling, plus any
 * aggregate leaves contributed to the catalog_product root through the
 * aggregate pool (E2) — the inventory pack's stock leaves qty / is_in_stock /
 * salable_qty (PRD-C1) when that pack is installed, and nothing when it is not.
 * Validation targets order-item snapshots or hydrated products; snapshot misses
 * (which includes every aggregate leaf) hydrate the full product through the
 * provider (AbstractWorkflowCondition).
 */
class Attribute extends AbstractWorkflowCondition
{
    private const SPECIAL_ATTRIBUTES = [
        'attribute_set_id' => 'Attribute Set',
        'category_ids' => 'Category',
        'sku' => 'SKU',
    ];

    /**
     * Aggregate-attribute metadata for the catalog_product root, resolved once
     * from the pool: code => ['label' => ..., 'input_type' => ...] (E2).
     *
     * @var array<string, array{label: string, input_type: string}>|null
     */
    private ?array $aggregateAttributes = null;

    public function __construct(
        Context $context,
        private readonly ProductAttributeCollectionFactory $attributeCollectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly AttributeSetCollectionFactory $attributeSetCollectionFactory,
        private readonly AggregateProviderPool $aggregateProviderPool,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * Aggregate attributes contributed to the catalog_product root via the
     * pool. Never present in trigger snapshots, so they always classify as
     * needs_hydration; absent-for-this-product aggregates (e.g. salable_qty
     * when MSI is not installed) then only match negative operators
     * (fail-toward-false).
     *
     * @return array<string, array{label: string, input_type: string}>
     */
    private function getAggregateAttributes(): array
    {
        return $this->aggregateAttributes ??=
            $this->aggregateProviderPool->getAttributeMetadata(HydrationProviderInterface::TYPE_PRODUCT);
    }

    /**
     * Special attributes + promo-rule-usable / searchable product EAV attributes
     * + aggregate leaves from the pool
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $attributes = [];
        foreach (self::SPECIAL_ATTRIBUTES as $code => $label) {
            $attributes[$code] = __($label);
        }
        foreach ($this->getAggregateAttributes() as $code => $meta) {
            $attributes[$code] = __($meta['label']);
        }
        try {
            $collection = $this->attributeCollectionFactory->create()->addFieldToFilter(
                ['is_used_for_promo_rules', 'is_searchable'],
                [1, 1]
            );
            foreach ($collection as $attribute) {
                $code = (string)$attribute->getAttributeCode();
                $label = trim((string)$attribute->getFrontendLabel());
                if ($code === '' || $label === '' || isset($attributes[$code])) {
                    continue;
                }
                $attributes[$code] = $label;
            }
        } catch (\Exception $e) {
            // Attribute metadata unavailable: special attributes remain usable
            unset($e);
        }
        $this->setAttributeOption($attributes);
        return $this;
    }

    /**
     * @return string
     */
    public function getInputType()
    {
        $code = (string)$this->getAttribute();
        $aggregates = $this->getAggregateAttributes();
        if (isset($aggregates[$code])) {
            return $aggregates[$code]['input_type'];
        }
        if ($code === 'attribute_set_id') {
            return 'select';
        }
        if ($code === 'category_ids') {
            // Simplified vs CatalogRule's dedicated "category" input: base
            // multiselect operators (is one of / is not one of) over the ids
            return 'multiselect';
        }
        $attribute = $this->getEavAttribute();
        if ($attribute === null) {
            return 'string';
        }
        if ($attribute->getFrontendInput() === 'price' || $attribute->getBackendType() === 'decimal') {
            return 'numeric';
        }
        return match ((string)$attribute->getFrontendInput()) {
            'select' => 'select',
            'multiselect' => 'multiselect',
            'date', 'datetime' => 'date',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        return match ($this->getInputType()) {
            'date' => 'date',
            'select', 'boolean' => 'select',
            'multiselect' => 'multiselect',
            default => 'text',
        };
    }

    /**
     * @return array
     */
    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            $options = [];
            $code = (string)$this->getAttribute();
            try {
                if ($code === 'attribute_set_id') {
                    $entityTypeId = (int)$this->eavConfig->getEntityType(Product::ENTITY)->getId();
                    $options = $this->attributeSetCollectionFactory->create()
                        ->setEntityTypeFilter($entityTypeId)
                        ->toOptionArray();
                } else {
                    $attribute = $this->getEavAttribute();
                    if ($attribute !== null && $attribute->usesSource()) {
                        $options = $attribute->getSource()->getAllOptions();
                    }
                }
            } catch (\Exception $e) {
                unset($e);
            }
            $this->setData('value_select_options', $options);
        }
        return $this->getData('value_select_options');
    }

    private function getEavAttribute(): ?\Magento\Eav\Model\Entity\Attribute\AbstractAttribute
    {
        $code = (string)$this->getAttribute();
        if ($code === '' || isset(self::SPECIAL_ATTRIBUTES[$code]) || isset($this->getAggregateAttributes()[$code])) {
            return null;
        }
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (\Exception $e) {
            unset($e);
            return null;
        }
        return $attribute->getAttributeId() ? $attribute : null;
    }
}
