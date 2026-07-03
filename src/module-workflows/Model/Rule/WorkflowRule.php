<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Rule\Model\AbstractModel;

/**
 * Transient rule model adapting Magento\Rule to workflows: the condition
 * combine is resolved per entity type from the ConditionCombinePool instead
 * of being hardcoded per rule class (docs/06-conditions.md).
 *
 * Never persisted — the workflow entity owns conditions_serialized; this
 * model only exists to load/render/validate the condition tree.
 */
class WorkflowRule extends AbstractModel
{
    public const KEY_ENTITY_TYPE = 'entity_type';

    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        TimezoneInterface $localeDate,
        private readonly ConditionCombinePool $conditionCombinePool,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $localeDate, $resource, $resourceCollection, $data);
    }

    public function setEntityType(string $entityType): self
    {
        $this->setData(self::KEY_ENTITY_TYPE, $entityType);
        return $this;
    }

    public function getEntityType(): string
    {
        return (string)$this->getData(self::KEY_ENTITY_TYPE);
    }

    /**
     * Entity-keyed root combine from the pool. setEntityType() before use.
     *
     * @return \Magento\Rule\Model\Condition\Combine
     */
    public function getConditionsInstance()
    {
        return $this->conditionCombinePool->getCombine($this->getEntityType());
    }

    /**
     * Workflows execute steps through the action framework (ActionPool /
     * definition step graph), not rule actions. The abstract contract still
     * requires an instance, so an inert conditions combine stands in — it is
     * never validated or applied.
     *
     * @return \Magento\Rule\Model\Condition\Combine
     */
    public function getActionsInstance()
    {
        return $this->getConditionsInstance();
    }
}
