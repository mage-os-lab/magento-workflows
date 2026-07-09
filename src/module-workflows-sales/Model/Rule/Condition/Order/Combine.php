<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Order;

use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\WorkflowsCustomer\Model\Rule\Condition\Customer\Combine as CustomerCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Root combine for sales_order workflow condition trees.
 *
 * Child choices:
 *  - Order Attribute leaves (flat sales_order columns);
 *  - Order Items subtree (ItemsFound: ANY/ALL product conditions over items);
 *  - Customer subtree (CustomerCombine: traverses order.customer_id →
 *    CustomerRepository through the hydration provider);
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
                ['value' => ItemsFound::class, 'label' => __('Order Items')],
                ['value' => CustomerCombine::class, 'label' => __('Customer')],
                ['value' => TriggerData::class, 'label' => __('Trigger Data (advanced)')],
                ['label' => __('Order Attribute'), 'value' => $attributeOptions],
            ],
            $this->relatedEntityChildOptions($this->relationPool, HydrationProviderInterface::TYPE_ORDER)
        );
    }
}
