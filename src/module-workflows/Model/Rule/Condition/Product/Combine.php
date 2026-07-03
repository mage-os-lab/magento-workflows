<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Condition\Product;

use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;

/**
 * Root combine for catalog_product workflow condition trees. The validated
 * model is the product snapshot (or hydrated product); attribute leaves
 * hydrate the full EAV-complete product on snapshot miss.
 */
class Combine extends AbstractWorkflowCombine
{
    public function __construct(
        Context $context,
        private readonly Attribute $conditionAttribute,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        $attributeOptions = [];
        foreach ($this->conditionAttribute->loadAttributeOptions()->getAttributeOption() as $code => $label) {
            $attributeOptions[] = ['value' => Attribute::class . '|' . $code, 'label' => $label];
        }
        return array_merge_recursive(
            parent::getNewChildSelectOptions(),
            [
                ['value' => self::class, 'label' => __('Conditions Combination')],
                ['value' => TriggerData::class, 'label' => __('Trigger Data (advanced)')],
                ['label' => __('Product Attribute'), 'value' => $attributeOptions],
            ]
        );
    }
}
