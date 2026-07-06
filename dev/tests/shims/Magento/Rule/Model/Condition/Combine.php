<?php
declare(strict_types=1);

namespace Magento\Rule\Model\Condition;

use Magento\Framework\DataObject;

/**
 * Minimal shim for Magento\Rule\Model\Condition\Combine — the subset the
 * workflow combines (AbstractWorkflowCombine and its subclasses) touch:
 * the child-condition bag, the aggregator, and the value the combine
 * validates against. The real class recreates child conditions from a
 * serialized array via the rule condition factory; unit tests build the
 * child list directly through setConditions(), so loadArray()/asArray() are
 * intentionally shallow here (real Magento supplies the full behavior).
 */
class Combine extends AbstractCondition
{
    public function __construct($context = null, array $data = [])
    {
        parent::__construct($context, $data);
        if (!$this->hasData('conditions')) {
            $this->setData('conditions', []);
        }
        if (!$this->hasData('aggregator')) {
            $this->setData('aggregator', 'all');
        }
    }

    /**
     * @return \Magento\Rule\Model\Condition\AbstractCondition[]
     */
    public function getConditions()
    {
        $conditions = $this->getData('conditions');
        return is_array($conditions) ? $conditions : [];
    }

    public function setConditions($conditions)
    {
        return $this->setData('conditions', $conditions);
    }

    public function addCondition($condition)
    {
        $conditions = $this->getConditions();
        $conditions[] = $condition;
        return $this->setData('conditions', $conditions);
    }

    public function getAggregator()
    {
        return $this->getData('aggregator');
    }

    public function setAggregator($aggregator)
    {
        return $this->setData('aggregator', $aggregator);
    }

    /**
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        return [];
    }

    /**
     * @return array
     */
    public function asArray(array $arrAttributes = [])
    {
        return [
            'type' => $this->getType(),
            'aggregator' => $this->getAggregator(),
            'value' => $this->getValue(),
            'conditions' => [],
        ];
    }

    /**
     * @param array $arr
     * @param string $key
     * @return $this
     */
    public function loadArray($arr, $key = 'conditions')
    {
        if (isset($arr['aggregator'])) {
            $this->setAggregator($arr['aggregator']);
        }
        if (array_key_exists('value', $arr)) {
            $this->setValue($arr['value']);
        }
        return $this;
    }

    public function validate(\Magento\Framework\DataObject $model)
    {
        return true;
    }
}
