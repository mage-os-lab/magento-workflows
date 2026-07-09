<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Quote;

use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\WorkflowsCustomer\Model\Rule\Condition\Customer\Combine as CustomerCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Root combine for quote workflow condition trees.
 *
 * Child choices:
 *  - Quote Attribute leaves (flat quote columns);
 *  - Customer subtree (CustomerCombine: traverses quote.customer_id →
 *    CustomerRepository through the hydration provider; guest quotes with no
 *    customer_id never match);
 *  - Trigger Data leaves (raw payload dot-path, advanced);
 *  - nested combinations of this combine.
 */
class Combine extends AbstractWorkflowCombine
{
    public function __construct(
        Context $context,
        private readonly Attribute $conditionAttribute,
        private readonly RelationPool $relationPool,
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
                ['value' => CustomerCombine::class, 'label' => __('Customer')],
                ['value' => TriggerData::class, 'label' => __('Trigger Data (advanced)')],
                ['label' => __('Quote Attribute'), 'value' => $attributeOptions],
            ],
            $this->relatedEntityChildOptions($this->relationPool, HydrationProviderInterface::TYPE_QUOTE)
        );
    }
}
