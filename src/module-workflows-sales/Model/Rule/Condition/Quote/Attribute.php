<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Quote;

use Magento\Rule\Model\Condition\Context;
use Magento\Store\Model\System\Store as SystemStore;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;

/**
 * Quote (cart) attribute condition over common flat quote columns.
 *
 * Snapshot-servable when the trigger payload carries the flat quote shape;
 * anything missing falls back to phase-2 hydration via
 * AbstractWorkflowCondition (QuoteHydrator through CartRepositoryInterface).
 */
class Attribute extends AbstractWorkflowCondition
{
    /**
     * attribute code => label (flat quote columns)
     */
    private const ATTRIBUTES = [
        'grand_total' => 'Grand Total',
        'base_grand_total' => 'Base Grand Total',
        'subtotal' => 'Subtotal',
        'items_count' => 'Items Count',
        'items_qty' => 'Items Quantity',
        'customer_email' => 'Customer Email',
        'customer_is_guest' => 'Customer Is Guest',
        'store_id' => 'Store View',
        'coupon_code' => 'Coupon Code',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
        'quote_currency_code' => 'Currency',
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
            'grand_total', 'base_grand_total', 'subtotal', 'items_count', 'items_qty' => 'numeric',
            'created_at', 'updated_at' => 'date',
            'customer_is_guest' => 'boolean',
            'store_id' => 'select',
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
                'store_id' => $this->systemStore->getStoreValuesForForm(),
                'customer_is_guest' => [
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
