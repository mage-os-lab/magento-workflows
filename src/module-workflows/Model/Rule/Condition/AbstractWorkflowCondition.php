<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Condition;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\AbstractCondition;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Base leaf condition with two-phase evaluation (docs/06-conditions.md).
 *
 * Phase 1 — snapshot pass: the validated model is a DataObject wrapping the
 * trigger payload. If it already carries the referenced attribute, validation
 * happens right there: zero queries.
 *
 * Phase 2 — hydration pass: on a snapshot miss, the hydration provider (see
 * HydrationProviderInterface convention keys placed on the model by the
 * ConditionEvaluator) loads the real entity through its repository —
 * memoized per validated model AND per (type, id) inside the provider — and
 * the attribute is validated against the hydrated, EAV-complete entity.
 *
 * Comparator coercion: numeric strings compare numerically and date
 * attributes are normalized via strtotime() before delegating to the core
 * operator implementation in AbstractCondition::validateAttribute().
 */
abstract class AbstractWorkflowCondition extends AbstractCondition
{
    /**
     * Model-local memoization key for the hydrated entity (false = miss)
     */
    private const KEY_HYDRATED = '__hydrated_entity';

    /**
     * Two-phase validation: snapshot first, hydrate on miss.
     *
     * Parameter is widened from the core AbstractModel to DataObject because
     * workflow snapshots are plain DataObjects, not ORM models.
     */
    public function validate(DataObject $model): bool
    {
        $attribute = (string)$this->getAttribute();
        if ($attribute === '') {
            return false;
        }
        if ($model->hasData($attribute)) {
            return (bool)$this->validateAttribute($model->getData($attribute));
        }
        $entity = $this->hydrateEntity($model);
        if ($entity !== null) {
            return (bool)$this->validateAttribute($entity->getData($attribute));
        }
        return (bool)$this->validateAttribute(null);
    }

    /**
     * Coerce before delegating to the core comparator:
     *  - attribute entirely absent (null): only negative operators can match;
     *  - date input: normalize the entity value via strtotime to Y-m-d so it
     *    lines up with the parsed rule value (day granularity, like core);
     *  - numeric strings: core validateAttribute()/_compareValues() already
     *    compare is_numeric operands numerically — leaned on as-is.
     *
     * @param mixed $validatedValue
     * @return bool
     */
    public function validateAttribute($validatedValue)
    {
        if ($validatedValue === null) {
            return in_array($this->getOperator(), ['!=', '!{}', '!()'], true);
        }
        if ($this->getInputType() === 'date' && is_scalar($validatedValue) && !is_bool($validatedValue)) {
            $timestamp = strtotime((string)$validatedValue);
            if ($timestamp !== false) {
                $validatedValue = date('Y-m-d', $timestamp);
            }
        }
        return (bool)parent::validateAttribute($validatedValue);
    }

    /**
     * Hydrate the execution entity for phase 2, memoized on the model
     */
    protected function hydrateEntity(DataObject $model): ?DataObject
    {
        if ($model->hasData(self::KEY_HYDRATED)) {
            $memoized = $model->getData(self::KEY_HYDRATED);
            return $memoized instanceof DataObject ? $memoized : null;
        }
        $provider = $model->getData(HydrationProviderInterface::KEY_PROVIDER);
        $entityType = (string)($model->getData(HydrationProviderInterface::KEY_ENTITY_TYPE) ?? '');
        $entityId = (int)($model->getData(HydrationProviderInterface::KEY_ENTITY_ID) ?? 0);
        if (!$provider instanceof HydrationProviderInterface || $entityType === '' || $entityId <= 0) {
            $model->setData(self::KEY_HYDRATED, false);
            return null;
        }
        $entity = $provider->getEntity(
            $entityType,
            $entityId,
            (bool)$model->getData(HydrationProviderInterface::KEY_FRESH)
        );
        $model->setData(self::KEY_HYDRATED, $entity ?? false);
        return $entity;
    }
}
