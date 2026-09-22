<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model\Rule\Condition\Subscriber;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;

/**
 * Newsletter subscriber condition subtree — the root combine of
 * newsletter_subscriber-entity workflows (SUB-C1).
 *
 * The validated model IS the subscriber: either the trigger snapshot built by
 * SubscriberHydrationService, or the phase-2 hydrated subscriber from
 * SubscriberHydrator. Children validate it directly.
 *
 * Deliberately minimal for v1 (per the core-coverage backlog): attribute
 * leaves, nested combinations and the generic Trigger Data leaf only. NO
 * relations and NO customer subtree — a subscriber links to a customer through
 * customer_id, but cross-entity traversal to the customer root is out of scope
 * here; the is_customer flag answers the common "guest vs. account holder"
 * question without a join. Revisit if a subscriber → customer relation is
 * requested.
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
                ['label' => __('Subscriber Attribute'), 'value' => $attributeOptions],
            ]
        );
    }

    public function validate(DataObject $model): bool
    {
        return $this->validateModel($model);
    }
}
