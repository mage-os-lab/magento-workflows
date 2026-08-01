<?php
declare(strict_types=1);

namespace Magento\Rule\Model\Condition;

use Magento\Framework\DataObject;

/**
 * Minimal shim for Magento\Rule\Model\Condition\AbstractCondition.
 * Implements operator comparison logic needed by AbstractWorkflowCondition,
 * plus the option/operator metadata surface ConditionMetaProvider reads
 * (loadAttributeOptions/loadValueOptions/getValueSelectOptions and the operator
 * maps) — those are copied from core verbatim, including the exact operator
 * sets per input type and the option labels, because the provider's projected
 * metadata IS those maps.
 */
abstract class AbstractCondition extends DataObject
{
    /**
     * Core operator sets per input type, verbatim from
     * Magento\Rule\Model\Condition\AbstractCondition::getDefaultOperatorInputByType()
     *
     * @var array<string, string[]>|null
     */
    private ?array $defaultOperatorInputByType = null;

    /**
     * @var array<string, \Magento\Framework\Phrase>|null
     */
    private ?array $defaultOperatorOptions = null;

    public function __construct($context = null, array $data = [])
    {
        // Ignore context (Magento\Rule\Model\Condition\Context), call parent with data only
        parent::__construct($data);
    }
    /**
     * Core operator comparison: mirrors Magento\Rule\Model\Condition\AbstractCondition logic
     */
    public function validateAttribute($value)
    {
        $operator = $this->getOperator();
        $compareValue = $this->getValueParsed() ?? $this->getValue();

        switch ($operator) {
            case '==':
                return $value == $compareValue;
            case '!=':
                return $value != $compareValue;
            case '>=':
                return $value >= $compareValue;
            case '<=':
                return $value <= $compareValue;
            case '>':
                return $value > $compareValue;
            case '<':
                return $value < $compareValue;
            case '()':
                // Set intersection when the validated value is itself a list
                // (multiselect columns like category_ids / applied_rule_ids):
                // mirrors core's array_intersect branch.
                if (is_array($value)) {
                    $needle = is_array($compareValue) ? $compareValue : explode(',', (string)$compareValue);
                    return count(array_intersect(array_map('strval', $value), array_map('strval', $needle))) > 0;
                }
                if (is_array($compareValue)) {
                    return in_array($value, $compareValue);
                }
                return in_array((string)$value, explode(',', (string)$compareValue));
            case '!()':
                if (is_array($value)) {
                    $needle = is_array($compareValue) ? $compareValue : explode(',', (string)$compareValue);
                    return count(array_intersect(array_map('strval', $value), array_map('strval', $needle))) === 0;
                }
                if (is_array($compareValue)) {
                    return !in_array($value, $compareValue);
                }
                return !in_array((string)$value, explode(',', (string)$compareValue));
            case '{}':
                return strpos((string)$value, (string)$compareValue) !== false;
            case '!{}':
                return strpos((string)$value, (string)$compareValue) === false;
        }
        return false;
    }

    public function getOperator()
    {
        return $this->getData('operator');
    }

    public function setOperator($operator)
    {
        return $this->setData('operator', $operator);
    }

    public function getValue()
    {
        return $this->getData('value');
    }

    public function setValue($value)
    {
        return $this->setData('value', $value);
    }

    public function getValueParsed()
    {
        return $this->getData('value_parsed');
    }

    public function setValueParsed($value)
    {
        return $this->setData('value_parsed', $value);
    }

    public function getIsValueParsed()
    {
        return (bool)$this->getData('is_value_parsed');
    }

    public function setIsValueParsed($flag)
    {
        return $this->setData('is_value_parsed', $flag);
    }

    public function getInputType()
    {
        return 'string';
    }

    public function getValueElementType()
    {
        return 'text';
    }

    public function getAttribute()
    {
        return $this->getData('attribute');
    }

    public function setAttribute($attribute)
    {
        return $this->setData('attribute', $attribute);
    }

    public function getType()
    {
        return $this->getData('type');
    }

    public function setType($type)
    {
        return $this->setData('type', $type);
    }

    /**
     * Core default: subclasses populate `attribute_option`
     *
     * @return $this
     */
    public function loadAttributeOptions()
    {
        return $this;
    }

    /**
     * Verbatim from Magento\Rule\Model\Condition\AbstractCondition.
     *
     * Present so a test double can replay it. The REAL constructor runs
     * `loadAttributeOptions()->loadOperatorOptions()->loadValueOptions()`, and
     * the real getOperatorSelectOptions() then iterates the `operator_option`
     * data this populates. A double that bypasses the constructor (the suite's
     * convention, since the real constructor demands a live rule Context) must
     * therefore call this itself, or the real getOperatorSelectOptions()
     * foreaches over null — a fatal on an install that this shim's own
     * self-contained getOperatorSelectOptions() would never surface.
     *
     * @return $this
     */
    public function loadOperatorOptions()
    {
        $this->setOperatorOption($this->getDefaultOperatorOptions());
        $this->setOperatorByInputType($this->getDefaultOperatorInputByType());
        return $this;
    }

    /**
     * Core default: an empty value-option hash (subclasses that offer a fixed
     * value select — the combines' TRUE/FALSE, FOUND/NOT FOUND, EXISTS/NOT
     * EXISTS — override this)
     *
     * @return $this
     */
    public function loadValueOptions()
    {
        $this->setValueOption([]);
        return $this;
    }

    /**
     * Core projection of the `value_option` hash into a select option list
     *
     * @return array<int, array{value: int|string, label: mixed}>
     */
    public function getValueSelectOptions()
    {
        $valueOption = [];
        if ($this->hasValueOption()) {
            $valueOption = (array)$this->getValueOption();
        }
        $options = [];
        foreach ($valueOption as $key => $value) {
            $options[] = ['value' => $key, 'label' => $value];
        }
        return $options;
    }

    /**
     * Operator sets per input type, verbatim from core
     *
     * @return array<string, string[]>
     */
    public function getDefaultOperatorInputByType()
    {
        if ($this->defaultOperatorInputByType === null) {
            $this->defaultOperatorInputByType = [
                'string' => ['==', '!=', '>=', '>', '<=', '<', '{}', '!{}', '()', '!()'],
                'numeric' => ['==', '!=', '>=', '>', '<=', '<', '()', '!()'],
                'date' => ['==', '>=', '<='],
                'select' => ['==', '!=', '<=>'],
                'boolean' => ['==', '!=', '<=>'],
                'multiselect' => ['{}', '!{}', '()', '!()'],
                'grid' => ['()', '!()'],
                'category' => ['==', '!=', '{}', '!{}', '()', '!()'],
            ];
        }
        return $this->defaultOperatorInputByType;
    }

    /**
     * Operator labels, verbatim from core (order included — it is the order the
     * rule widget's operator select renders)
     *
     * @return array<string, \Magento\Framework\Phrase>
     */
    public function getDefaultOperatorOptions()
    {
        if ($this->defaultOperatorOptions === null) {
            $this->defaultOperatorOptions = [
                '==' => __('is'),
                '!=' => __('is not'),
                '>=' => __('equals or greater than'),
                '<=' => __('equals or less than'),
                '>' => __('greater than'),
                '<' => __('less than'),
                '{}' => __('contains'),
                '!{}' => __('does not contain'),
                '()' => __('is one of'),
                '!()' => __('is not one of'),
                '<=>' => __('is undefined'),
            ];
        }
        return $this->defaultOperatorOptions;
    }

    /**
     * Operators legal for this condition's current input type, null when the
     * input type is unknown (core then allows every operator)
     *
     * @return string[]|null
     */
    public function getOperatorByInputType()
    {
        return $this->getDefaultOperatorInputByType()[$this->getInputType()] ?? null;
    }

    /**
     * The two maps composed the way core composes them for the operator select
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getOperatorSelectOptions()
    {
        $operatorByType = $this->getOperatorByInputType();
        $options = [];
        foreach ($this->getDefaultOperatorOptions() as $operator => $label) {
            if (!$operatorByType || in_array($operator, $operatorByType, true)) {
                $options[] = ['value' => $operator, 'label' => $label];
            }
        }
        return $options;
    }

    public function getForm()
    {
        throw new \RuntimeException('getForm not available in test shim');
    }

    public function getPrefix()
    {
        return $this->getData('prefix') ?? 'conditions';
    }

    public function setPrefix($prefix)
    {
        return $this->setData('prefix', $prefix);
    }

    public function getId()
    {
        return $this->getData('id') ?? 'new';
    }

    public function setId($id)
    {
        return $this->setData('id', $id);
    }

    abstract public function validate(\Magento\Framework\DataObject $model);
}
