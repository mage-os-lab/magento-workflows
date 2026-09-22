<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Condition\RelatedEntity;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Context;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\AbstractWorkflowCombine;
use MageOS\Workflows\Model\Rule\Condition\TriggerData;
use Psr\Log\LoggerInterface;

/**
 * Generic related-entity condition (docs/discovery/entity-cross-referencing.md,
 * approach R2): "from the trigger entity, follow a registered relation, and
 * assert something about the related target entity." One class powers every
 * relation in the pool — the merchant picks a relation code, an existence
 * value, and (for to-many relations) a match mode.
 *
 * Value semantics borrowed from Order\ItemsFound: EXISTS / NOT EXISTS. The
 * flagship guest check is literally: Order → *Customer matching the order
 * email* → NOT EXISTS.
 *
 * Child conditions run against the resolved target entity, reusing the
 * target's own leaf classes (Customer Attribute, Order Attribute, ...). An
 * EXISTS with children means "a related entity exists AND matches"; NOT EXISTS
 * ignores children — and because the two operators stop being complements once
 * children are present, NOT-EXISTS-with-children is a hard save error
 * (RelationConditionsCheck), never reached here in a valid definition.
 *
 * Cardinality-many adds ANY / ALL / NONE over the (capped) resolved id list:
 *  - ANY   — at least one related entity matches the children;
 *  - ALL   — every related entity matches (empty set = false);
 *  - NONE  — no related entity matches (empty set = true).
 * The cap (mageos_workflows/guards/relation_cap) is applied in RelationContext.
 * ANY/NONE honestly evaluate the first N (a match in the first N is a match);
 * ALL over a truncated set is unknowable and fails toward false with a warning.
 *
 * All resolution goes through RelationContext (memoization, website scoping,
 * fail-toward-false); this combine never calls RelationInterface::resolveIds().
 */
class Combine extends AbstractWorkflowCombine
{
    public const MATCH_ANY = 'any';
    public const MATCH_ALL = 'all';
    public const MATCH_NONE = 'none';

    public const EXISTS = 1;
    public const NOT_EXISTS = 0;

    /**
     * @param array<string, \Magento\Rule\Model\Condition\AbstractCondition> $targetChildConditions
     *        target entity type => the target's Attribute leaf condition,
     *        offered as child options once a relation of that target is chosen
     *        (di.xml-registered; third-party relation packs append their own)
     */
    public function __construct(
        Context $context,
        private readonly RelationContext $relationContext,
        private readonly RelationPool $relationPool,
        private readonly LoggerInterface $logger,
        private readonly array $targetChildConditions = [],
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->setType(self::class);
    }

    public function getRelation(): string
    {
        return trim((string) $this->getData('relation'));
    }

    public function getMatchMode(): string
    {
        $mode = (string) $this->getData('match_mode');
        return in_array($mode, [self::MATCH_ANY, self::MATCH_ALL, self::MATCH_NONE], true)
            ? $mode
            : self::MATCH_ANY;
    }

    /**
     * @return $this
     */
    public function loadValueOptions()
    {
        $this->setValueOption([self::EXISTS => __('EXISTS'), self::NOT_EXISTS => __('NOT EXISTS')]);
        return $this;
    }

    /**
     * @return string
     */
    public function getValueElementType()
    {
        return 'select';
    }

    /**
     * Persist the two combine-specific keys alongside the core combine shape
     *
     * @return array
     */
    public function asArray(array $arrAttributes = [])
    {
        $out = parent::asArray($arrAttributes);
        $out['relation'] = $this->getRelation();
        $out['match_mode'] = $this->getMatchMode();
        return $out;
    }

    /**
     * @param array $arr
     * @param string $key
     * @return $this
     */
    public function loadArray($arr, $key = 'conditions')
    {
        $this->setData('relation', $arr['relation'] ?? '');
        $this->setData('match_mode', $arr['match_mode'] ?? self::MATCH_ANY);
        return parent::loadArray($arr, $key);
    }

    /**
     * Child options are the chosen relation's target-entity leaves. Before a
     * relation is picked (or for an unknown code) every registered target's
     * options are offered; once picked, they narrow to that target.
     *
     * @return array
     */
    public function getNewChildSelectOptions()
    {
        $targets = $this->targetChildConditions;
        $relationCode = $this->getRelation();
        if ($relationCode !== '' && $this->relationPool->has($relationCode)) {
            $targetType = $this->relationPool->get($relationCode)->getTargetEntityType();
            if (isset($targets[$targetType])) {
                $targets = [$targetType => $targets[$targetType]];
            }
        }

        $groups = [
            ['value' => self::class, 'label' => __('Conditions Combination')],
            ['value' => TriggerData::class, 'label' => __('Trigger Data (advanced)')],
        ];
        foreach ($targets as $targetType => $condition) {
            $attributeOptions = [];
            foreach ($condition->loadAttributeOptions()->getAttributeOption() as $code => $label) {
                $attributeOptions[] = ['value' => get_class($condition) . '|' . $code, 'label' => $label];
            }
            if ($attributeOptions !== []) {
                // Name the target entity in the heading: with no relation
                // chosen yet, EVERY registered target's attributes are offered,
                // and two groups both called "Related Attribute" are
                // indistinguishable ("Created At" exists on customers AND
                // orders).
                $groups[] = [
                    'label' => __('Related Attribute (%1)', (string) $targetType),
                    'value' => $attributeOptions,
                ];
            }
        }

        return array_merge_recursive(parent::getNewChildSelectOptions(), $groups);
    }

    /**
     * EXISTS/NOT EXISTS against the resolved relation, qualified by children.
     *
     * @param DataObject $model the source (trigger) entity being evaluated
     */
    public function validate(DataObject $model): bool
    {
        $relationCode = $this->getRelation();
        if ($relationCode === '') {
            // Misconfigured node: fail toward false rather than throw.
            $this->logger->warning('RelatedEntity condition has no relation code; evaluating false');
            return false;
        }

        $expectedExists = $this->getValue() === null ? true : (bool) $this->getValue();
        $this->relationContext->setFresh((bool) $model->getData(\MageOS\Workflows\Model\Rule\HydrationProviderInterface::KEY_FRESH));
        $ids = $this->relationContext->resolve($relationCode, $model);

        $conditions = $this->getConditions();
        if (!is_array($conditions) || $conditions === []) {
            // Bare existence check — the flagship guest case lives here.
            return ($ids !== []) === $expectedExists;
        }

        return $this->qualifiedMatch($model, $relationCode, $ids) === $expectedExists;
    }

    /**
     * Resolve each target entity and reduce its per-entity child match by the
     * configured match mode.
     *
     * @param int[] $ids
     */
    private function qualifiedMatch(DataObject $model, string $relationCode, array $ids): bool
    {
        $mode = $this->getMatchMode();
        if ($ids === []) {
            // "all of none" / "any of none" is false; "none of none" is true.
            return $mode === self::MATCH_NONE;
        }

        $provider = $this->getHydrationProvider($model);
        if ($provider === null) {
            return false;
        }

        if ($mode === self::MATCH_ALL && count($ids) >= $this->relationContext->getCap()) {
            // Truncated to-many set: an ALL claim over it is unknowable.
            $this->logger->warning(sprintf(
                'RelatedEntity relation "%s" hit the resolution cap; ALL match is '
                . 'indeterminable and evaluates false',
                $relationCode
            ));
            return false;
        }

        $targetType = $this->relationPool->get($relationCode)->getTargetEntityType();
        $anyMatched = false;
        $allMatched = true;
        $evaluated = 0;
        foreach ($ids as $id) {
            $entity = $provider->getEntity($targetType, (int) $id, $this->relationContext->isFresh());
            if ($entity === null) {
                $allMatched = false;
                continue;
            }
            $this->propagateHydrationKeys($model, $entity, $targetType, (int) $id);
            $evaluated++;
            if ($this->childrenMatch($entity)) {
                $anyMatched = true;
                if ($mode === self::MATCH_ANY || $mode === self::MATCH_NONE) {
                    break;
                }
            } else {
                $allMatched = false;
                if ($mode === self::MATCH_ALL) {
                    break;
                }
            }
        }

        return match ($mode) {
            self::MATCH_ALL => $allMatched && $evaluated > 0,
            self::MATCH_NONE => !$anyMatched,
            default => $anyMatched,
        };
    }

    /**
     * Aggregate the child conditions against a single hydrated target entity
     * (aggregator all/any; an empty child list matches every entity — mirrors
     * core combine semantics). Kept separate from validateModel() because that
     * method reads getValue() as the expected boolean, which here is the
     * EXISTS/NOT-EXISTS polarity, not the child aggregation target.
     */
    private function childrenMatch(DataObject $entity): bool
    {
        $conditions = $this->getConditions();
        if (!is_array($conditions) || $conditions === []) {
            return true;
        }
        $all = $this->getAggregator() === 'all';
        foreach ($conditions as $condition) {
            $validated = (bool) $condition->validate($entity);
            if ($all && !$validated) {
                return false;
            }
            if (!$all && $validated) {
                return true;
            }
        }
        return $all;
    }
}
