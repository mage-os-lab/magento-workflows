<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCatalog\Model\Rule\Condition\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;

/**
 * Product attribute condition with EAV introspection (CatalogRule product
 * condition pattern, simplified).
 *
 * loadAttributeOptions() offers every product attribute flagged usable in
 * promo rules or searchable — custom EAV attributes included automatically —
 * plus the special attribute_set_id and category_ids handling. Validation
 * targets order-item snapshots or hydrated products; snapshot misses hydrate
 * the full product through the provider (AbstractWorkflowCondition).
 */
class Attribute extends AbstractWorkflowCondition
{
    private const SPECIAL_ATTRIBUTES = [
        'attribute_set_id' => 'Attribute Set',
        'category_ids' => 'Category',
        'sku' => 'SKU',
    ];

    public function __construct(
        Context $context,
        private readonly ProductAttributeCollectionFactory $attributeCollectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly AttributeSetCollectionFactory $attributeSetCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * Special attributes + promo-rule-usable / searchable product EAV attributes
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $attributes = [];
        foreach (self::SPECIAL_ATTRIBUTES as $code => $label) {
            $attributes[$code] = __($label);
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
        if ($code === '' || isset(self::SPECIAL_ATTRIBUTES[$code])) {
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
