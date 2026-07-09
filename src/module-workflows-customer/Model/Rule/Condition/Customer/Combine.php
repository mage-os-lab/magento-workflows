<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model\Rule\Condition\Customer;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Customer condition subtree.
 *
 * Used two ways:
 *  - as the root combine of customer-entity workflows: the validated model IS
 *    the customer (snapshot or hydrated), children validate it directly;
 *  - as a cross-entity child of the Order combine: the validated model is an
 *    order — the combine traverses order.customer_id → CustomerRepository via
 *    the hydration provider and validates children against the hydrated,
 *    EAV-complete customer. Guest orders (no customer_id) never match.
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
                ['value' => TriggerData::class, 'label' => __('Trigger Data (advanced)')],
                ['label' => __('Customer Attribute'), 'value' => $attributeOptions],
            ],
            $this->relatedEntityChildOptions($this->relationPool, HydrationProviderInterface::TYPE_CUSTOMER)
        );
    }

    public function validate(DataObject $model): bool
    {
        $customer = $this->resolveCustomer($model);
        if ($customer === null) {
            return false;
        }
        return $this->validateModel($customer);
    }

    private function resolveCustomer(DataObject $model): ?DataObject
    {
        $contextType = (string)($model->getData(HydrationProviderInterface::KEY_ENTITY_TYPE) ?? '');
        if ($contextType === HydrationProviderInterface::TYPE_CUSTOMER || $contextType === '') {
            // Already a customer model (or an unwired bare snapshot): validate in place
            return $model;
        }
        $customerId = (int)($model->getData('customer_id') ?: 0);
        if ($customerId <= 0) {
            return null;
        }
        $provider = $this->getHydrationProvider($model);
        if ($provider === null) {
            return null;
        }
        $customer = $provider->getEntity(
            HydrationProviderInterface::TYPE_CUSTOMER,
            $customerId,
            (bool)$model->getData(HydrationProviderInterface::KEY_FRESH)
        );
        if ($customer !== null) {
            $this->propagateHydrationKeys($model, $customer, HydrationProviderInterface::TYPE_CUSTOMER, $customerId);
        }
        return $customer;
    }
}
