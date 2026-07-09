<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Quote;

use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\ConditionCombinePool;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Root combine for quote workflow condition trees.
 *
 * Child choices:
 *  - Quote Attribute leaves (flat quote columns);
 *  - Cart Items subtree (ItemsFound: ANY/ALL product conditions over items,
 *    QTE-C1);
 *  - Customer subtree (the customer pack's root combine, resolved through ConditionCombinePool: traverses quote.customer_id →
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
        private readonly ConditionCombinePool $combinePool,
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
        // The Customer subtree is the customer pack's root combine, resolved
        // by entity type through the engine pool so this pack never
        // compile-references the customer pack; without it the subtree is
        // simply not offered.
        $customerOptions = [];
        $customerCombineClass = $this->combinePool->getCombineClass(HydrationProviderInterface::TYPE_CUSTOMER);
        if ($customerCombineClass !== null) {
            $customerOptions[] = ['value' => $customerCombineClass, 'label' => __('Customer')];
        }
        return array_merge_recursive(
            parent::getNewChildSelectOptions(),
            [
                ['value' => self::class, 'label' => __('Conditions Combination')],
                ['value' => ItemsFound::class, 'label' => __('Cart Items')],
                ...$customerOptions,
                ['value' => TriggerData::class, 'label' => __('Trigger Data (advanced)')],
                ['label' => __('Quote Attribute'), 'value' => $attributeOptions],
            ],
            $this->relatedEntityChildOptions($this->relationPool, HydrationProviderInterface::TYPE_QUOTE)
        );
    }
}
