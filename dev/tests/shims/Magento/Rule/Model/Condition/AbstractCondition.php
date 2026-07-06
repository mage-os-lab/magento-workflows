<?php
declare(strict_types=1);

namespace Magento\Rule\Model\Condition;

use Magento\Framework\DataObject;

/**
 * Minimal shim for Magento\Rule\Model\Condition\AbstractCondition.
 * Implements operator comparison logic needed by AbstractWorkflowCondition.
 */
abstract class AbstractCondition extends DataObject
{
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
                if (is_array($compareValue)) {
                    return in_array($value, $compareValue);
                }
                return in_array((string)$value, explode(',', (string)$compareValue));
            case '!()':
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
