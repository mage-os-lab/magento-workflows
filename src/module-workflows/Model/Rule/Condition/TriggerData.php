<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Condition;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;

/**
 * "Trigger Data (advanced)" leaf: matches a free-text dot-path into the raw
 * trigger payload snapshot against a value, with the full string operator
 * set. Path segments traverse nested arrays, e.g.:
 *
 *  - 'from_status' / 'to_status'      sales.order.status_changed payload
 *  - 'from_group_id' / 'to_group_id'  customer.group_changed payload
 *  - 'payment.method'                 nested order payment structure
 *  - 'items.0.sku'                    first order item's SKU
 *
 * Snapshot-only BY DESIGN — no phase-2 hydration: trigger metadata (from/to
 * values and other transition context) exists only in the payload, so
 * re-loading the entity through a repository could never produce it. A
 * missing path resolves to null, which (per AbstractWorkflowCondition) only
 * the negative operators (!=, !{}, !()) can match — fail-toward-false. This
 * also means post-delay revalidation (revalidate_entity: true), which
 * validates the freshly hydrated entity instead of the snapshot, only sees
 * keys the entity itself carries.
 */
class TriggerData extends AbstractWorkflowCondition
{
    public function __construct(
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    /**
     * Free-text dot-path — no fixed attribute option list
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        $this->setAttributeOption([]);
        return $this;
    }

    /**
     * The raw dot-path doubles as its own display name
     *
     * @return string
     */
    public function getAttributeName()
    {
        return (string)$this->getAttribute();
    }

    /**
     * Text input instead of the core attribute select
     *
     * @return \Magento\Framework\Data\Form\Element\AbstractElement
     */
    public function getAttributeElement()
    {
        return $this->getForm()->addField(
            $this->getPrefix() . '__' . $this->getId() . '__attribute',
            'text',
            [
                'name' => $this->elementName . '[' . $this->getPrefix() . '][' . $this->getId() . '][attribute]',
                'value' => $this->getAttribute(),
                'value_name' => $this->getAttributeName(),
                'data-form-part' => $this->getFormName(),
            ]
        )->setRenderer(
            $this->_layout->getBlockSingleton(\Magento\Rule\Block\Editable::class)
        );
    }

    /**
     * Full string operator set (==, !=, >=, >, <=, <, {}, !{}, (), !())
     *
     * @return string
     */
    public function getInputType()
    {
        return 'string';
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        return 'text';
    }

    /**
     * Dot-path resolution against the snapshot only — no hydration fallback
     */
    public function validate(DataObject $model): bool
    {
        $path = trim((string)$this->getAttribute());
        if ($path === '') {
            return false;
        }
        return (bool)$this->validateAttribute($this->resolvePath($model, $path));
    }

    /**
     * Resolve a dot-path against the model data with array traversal;
     * any missing segment yields null (negative-operators-only semantics)
     */
    private function resolvePath(DataObject $model, string $path): mixed
    {
        $segments = explode('.', $path);
        $root = (string)array_shift($segments);
        $value = $model->hasData($root) ? $model->getData($root) : null;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
