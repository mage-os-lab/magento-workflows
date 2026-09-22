<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsNewsletter\Model\Rule\Condition\Subscriber;

use Magento\Rule\Model\Condition\Context;
use Magento\Store\Model\System\Store as SystemStore;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;
use MageOS\WorkflowsNewsletter\Model\SubscriberStatus;

/**
 * Newsletter subscriber attribute condition over the flat subscriber_subscriber
 * table columns (SUB-C1). No EAV — the subscriber is a flat table — so the
 * offered attributes are a fixed, curated list.
 *
 * Snapshot-servable whenever the trigger payload carries the flat subscriber
 * shape (newsletter.subscription_changed builds exactly that via
 * SubscriberHydrationService); anything referenced but missing falls back to
 * phase-2 hydration through SubscriberHydrator (HydrationProvider), keyed by
 * subscriber_id.
 *
 *  - subscriber_email   string   the subscriber's email (guests included)
 *  - subscriber_status  select   Subscriber::STATUS_* (Subscribed / Not Active
 *                                / Unsubscribed / Unconfirmed)
 *  - store_id           select   subscription store view (SystemStore options,
 *                                like the quote condition)
 *  - is_customer        boolean  linked to a customer account (customer_id > 0)
 *  - change_status_at   date     last status-change timestamp
 */
class Attribute extends AbstractWorkflowCondition
{
    /**
     * attribute code => label (flat subscriber columns / derived flags)
     */
    private const ATTRIBUTES = [
        'subscriber_email' => 'Subscriber Email',
        'subscriber_status' => 'Subscription Status',
        'store_id' => 'Store View',
        'is_customer' => 'Linked to Customer Account',
        'change_status_at' => 'Status Changed At',
    ];

    public function __construct(
        Context $context,
        private readonly SystemStore $systemStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $attributes = [];
        foreach (self::ATTRIBUTES as $code => $label) {
            $attributes[$code] = __($label);
        }
        $this->setAttributeOption($attributes);
        return $this;
    }

    /**
     * @return string
     */
    public function getInputType()
    {
        return match ((string)$this->getAttribute()) {
            'subscriber_status', 'store_id' => 'select',
            'is_customer' => 'boolean',
            'change_status_at' => 'date',
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
            default => 'text',
        };
    }

    /**
     * @return array
     */
    public function getValueSelectOptions()
    {
        if (!$this->hasData('value_select_options')) {
            $options = match ((string)$this->getAttribute()) {
                'subscriber_status' => SubscriberStatus::options(),
                'store_id' => $this->systemStore->getStoreValuesForForm(),
                'is_customer' => [
                    ['value' => 1, 'label' => __('Yes')],
                    ['value' => 0, 'label' => __('No')],
                ],
                default => [],
            };
            $this->setData('value_select_options', $options);
        }
        return $this->getData('value_select_options');
    }
}
