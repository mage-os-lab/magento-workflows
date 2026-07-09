<?php
declare(strict_types=1);

namespace MageOS\WorkflowsSales\Model\Rule\Condition\Order;

use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as CustomerGroupCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Payment\Model\Config\Source\Allmethods as PaymentMethods;
use Magento\Rule\Model\Condition\Context;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Shipping\Model\Config\Source\Allmethods as ShippingMethods;
use Magento\Store\Model\System\Store as SystemStore;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;

/**
 * Order attribute condition over common flat sales_order columns.
 *
 * Fully snapshot-servable for event triggers riding the async-events order
 * payload; anything missing (e.g. shipping_method on a slim payload) falls
 * back to phase-2 hydration via AbstractWorkflowCondition.
 */
class Attribute extends AbstractWorkflowCondition
{
    /**
     * attribute code => label (flat sales_order columns + derived payment_method)
     */
    private const ATTRIBUTES = [
        'status' => 'Order Status',
        'state' => 'Order State',
        'grand_total' => 'Grand Total',
        'subtotal' => 'Subtotal',
        'total_qty_ordered' => 'Total Quantity Ordered',
        'customer_group_id' => 'Customer Group',
        'store_id' => 'Store View',
        'payment_method' => 'Payment Method',
        'shipping_method' => 'Shipping Method',
        'customer_email' => 'Customer Email',
        'coupon_code' => 'Coupon Code',
        'weight' => 'Weight',
        'created_at' => 'Created At',
        'order_currency_code' => 'Order Currency',
        'discount_amount' => 'Discount Amount',
        'total_paid' => 'Total Paid',
        'total_refunded' => 'Total Refunded',
        'customer_is_guest' => 'Customer Is Guest',
        'billing_country' => 'Billing Country',
        'billing_region' => 'Billing State/Province',
        'billing_postcode' => 'Billing Postcode',
        'billing_city' => 'Billing City',
        'shipping_country' => 'Shipping Country',
        'shipping_region' => 'Shipping State/Province',
        'shipping_postcode' => 'Shipping Postcode',
        'shipping_city' => 'Shipping City',
    ];

    /**
     * Attributes readable from a nested snapshot structure when the flat key
     * is absent: attribute => [payload key, nested key]. On a full miss the
     * hydrated order carries these as flat keys (OrderHydrator emits flat
     * payment_method and billing_/shipping_ address basics).
     */
    private const NESTED_SOURCES = [
        'payment_method' => ['payment', 'method'],
        'billing_country' => ['billing_address', 'country_id'],
        'billing_region' => ['billing_address', 'region'],
        'billing_postcode' => ['billing_address', 'postcode'],
        'billing_city' => ['billing_address', 'city'],
        'shipping_country' => ['shipping_address', 'country_id'],
        'shipping_region' => ['shipping_address', 'region'],
        'shipping_postcode' => ['shipping_address', 'postcode'],
        'shipping_city' => ['shipping_address', 'city'],
    ];

    public function __construct(
        Context $context,
        private readonly OrderConfig $orderConfig,
        private readonly CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        private readonly SystemStore $systemStore,
        private readonly PaymentMethods $paymentMethods,
        private readonly ShippingMethods $shippingMethods,
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
            'grand_total', 'subtotal', 'total_qty_ordered', 'weight',
            'discount_amount', 'total_paid', 'total_refunded' => 'numeric',
            'created_at' => 'date',
            'customer_is_guest' => 'boolean',
            'status', 'state', 'customer_group_id', 'store_id', 'payment_method', 'shipping_method' => 'select',
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
                'status' => $this->hashToOptions($this->orderConfig->getStatuses()),
                'state' => $this->hashToOptions($this->orderConfig->getStates()),
                'customer_group_id' => $this->customerGroupCollectionFactory->create()->toOptionArray(),
                'store_id' => $this->systemStore->getStoreValuesForForm(),
                'payment_method' => $this->paymentMethods->toOptionArray(),
                'shipping_method' => $this->shippingMethods->toOptionArray(),
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

    public function validate(DataObject $model): bool
    {
        $attribute = (string)$this->getAttribute();
        if (!$model->hasData($attribute) && isset(self::NESTED_SOURCES[$attribute])) {
            [$payloadKey, $nestedKey] = self::NESTED_SOURCES[$attribute];
            $nested = $model->getData($payloadKey);
            if (is_array($nested) && array_key_exists($nestedKey, $nested)) {
                return $this->validateAttribute($nested[$nestedKey]);
            }
        }
        return parent::validate($model);
    }

    /**
     * @param array<int|string, mixed> $hash
     * @return array<int, array{value: int|string, label: mixed}>
     */
    private function hashToOptions(array $hash): array
    {
        $options = [];
        foreach ($hash as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }
        return $options;
    }
}
