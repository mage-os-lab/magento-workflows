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
use MageOS\Workflows\Model\Rule\AggregateProviderPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCondition;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;
use MageOS\WorkflowsSales\Model\Option\CartPriceRuleOptionSource;

/**
 * Order attribute condition over common flat sales_order columns, plus the
 * lifecycle-flag aggregates contributed to the order root through the
 * AggregateProviderPool (ORD-C1 / E2) — can_invoice, can_ship, can_creditmemo,
 * is_virtual, invoice_count, shipment_count — plus the `applied_rule_ids`
 * multiselect (ORD-C2).
 *
 * The flat columns are fully snapshot-servable for event triggers riding the
 * async-events order payload; anything missing (e.g. shipping_method on a slim
 * payload) falls back to phase-2 hydration via AbstractWorkflowCondition. The
 * aggregates are never present in a snapshot, so they always classify as
 * needs_hydration and resolve in phase 2 against the hydrated order (OrderHydrator
 * merges them through the same pool); absent-for-this-order aggregates then
 * only match the negative operators (fail-toward-false).
 *
 * applied_rule_ids (ORD-C2) is the flat, comma-separated list of cart-price-rule
 * ids the order matched (a snapshot-friendly sales_order column). It is a
 * multiselect handled with SET semantics, mirroring category_ids in the catalog
 * pack's Product/Attribute: the stored "1,4,7" string is exploded into a list so
 * the `is one of` / `is not one of` operators reduce to set intersection against
 * the selected rule ids (core's array_intersect branch). Options come from the
 * pack's CartPriceRuleOptionSource. An order that matched NO rule is the empty
 * set — "is one of X" is false, "is not one of X" is true — which is a genuine
 * answer, distinct from an indeterminable (absent) attribute that only the
 * negative operators match.
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
        'applied_rule_ids' => 'Applied Cart Price Rules',
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

    /**
     * Aggregate-attribute metadata for the order root, resolved once from the
     * pool: code => ['label' => ..., 'input_type' => ...] (E2 / ORD-C1).
     *
     * @var array<string, array{label: string, input_type: string}>|null
     */
    private ?array $aggregateAttributes = null;

    public function __construct(
        Context $context,
        private readonly OrderConfig $orderConfig,
        private readonly CustomerGroupCollectionFactory $customerGroupCollectionFactory,
        private readonly SystemStore $systemStore,
        private readonly PaymentMethods $paymentMethods,
        private readonly ShippingMethods $shippingMethods,
        private readonly AggregateProviderPool $aggregateProviderPool,
        private readonly CartPriceRuleOptionSource $cartPriceRuleOptionSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * Aggregate attributes contributed to the order root via the pool, resolved
     * lazily and cached for the life of the condition instance.
     *
     * @return array<string, array{label: string, input_type: string}>
     */
    private function getAggregateAttributes(): array
    {
        return $this->aggregateAttributes ??=
            $this->aggregateProviderPool->getAttributeMetadata(HydrationProviderInterface::TYPE_ORDER);
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
        foreach ($this->getAggregateAttributes() as $code => $meta) {
            $attributes[$code] = __($meta['label']);
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
        return match ($code) {
            'grand_total', 'subtotal', 'total_qty_ordered', 'weight',
            'discount_amount', 'total_paid', 'total_refunded' => 'numeric',
            'created_at' => 'date',
            'customer_is_guest' => 'boolean',
            'status', 'state', 'customer_group_id', 'store_id', 'payment_method', 'shipping_method' => 'select',
            'applied_rule_ids' => 'multiselect',
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
            $options = match ((string)$this->getAttribute()) {
                'status' => $this->hashToOptions($this->orderConfig->getStatuses()),
                'state' => $this->hashToOptions($this->orderConfig->getStates()),
                'customer_group_id' => $this->customerGroupCollectionFactory->create()->toOptionArray(),
                'store_id' => $this->systemStore->getStoreValuesForForm(),
                'payment_method' => $this->paymentMethods->toOptionArray(),
                'shipping_method' => $this->shippingMethods->toOptionArray(),
                // Cart-price-rule ids from the pack's search-typed option source
                // (F6); capped at the source's RESULT_LIMIT.
                'applied_rule_ids' => $this->cartPriceRuleOptionSource->fetch(),
                default => [],
            };
            // Yes/No for every boolean attribute — the flat customer_is_guest
            // flag and the boolean lifecycle aggregates (can_invoice, ...) alike.
            if ($options === [] && $this->getInputType() === 'boolean') {
                $options = [
                    ['value' => 1, 'label' => __('Yes')],
                    ['value' => 0, 'label' => __('No')],
                ];
            }
            $this->setData('value_select_options', $options);
        }
        return $this->getData('value_select_options');
    }

    public function validate(DataObject $model): bool
    {
        $attribute = (string)$this->getAttribute();

        // applied_rule_ids is a flat comma-separated column matched as a SET:
        // explode it (snapshot-first, hydrate on miss) so the `is one of` /
        // `is not one of` operators reduce to a set intersection.
        if ($attribute === 'applied_rule_ids') {
            return $this->validateAppliedRuleIds($model);
        }

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
     * Set-match the order's applied_rule_ids, snapshot-first then hydrated.
     * A present-but-empty list is the empty set (a real answer); a fully absent
     * attribute (neither snapshot nor hydrated order carries it) stays null so
     * only the negative operators match (fail-toward-false).
     */
    private function validateAppliedRuleIds(DataObject $model): bool
    {
        if ($model->hasData('applied_rule_ids')) {
            return (bool)$this->validateAttribute($this->splitRuleIds($model->getData('applied_rule_ids')));
        }
        $entity = $this->hydrateEntity($model);
        if ($entity !== null) {
            return (bool)$this->validateAttribute($this->splitRuleIds($entity->getData('applied_rule_ids')));
        }
        return (bool)$this->validateAttribute(null);
    }

    /**
     * Normalize a stored applied_rule_ids value ("1,4,7", or an already-split
     * array) into a list of non-empty id strings.
     *
     * @return string[]
     */
    private function splitRuleIds(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw) || is_numeric($raw)) {
            $parts = preg_split('/\s*,\s*/', trim((string)$raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $parts = [];
        }
        return array_values(array_filter(
            array_map(static fn ($v): string => trim((string)$v), $parts),
            static fn (string $v): bool => $v !== ''
        ));
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
