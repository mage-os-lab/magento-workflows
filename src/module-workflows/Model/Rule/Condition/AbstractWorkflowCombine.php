<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Rule\Condition;

use Magento\Framework\DataObject;
use Magento\Rule\Model\Condition\Combine;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\Condition\RelatedEntity\Combine as RelatedEntityCombine;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Base combine for workflow condition trees.
 *
 * Differences from the core rule combine:
 *  - validate() accepts any DataObject — trigger snapshots are wrapped
 *    payload arrays, not ORM models (parameter widening, PHP 7.4+);
 *  - short-circuit ordering (docs/06-conditions.md): leaf conditions whose
 *    attribute is already present on the validated model run first, so under
 *    ALL an early false — the common case — never triggers a hydration.
 */
abstract class AbstractWorkflowCombine extends Combine
{
    public function validate(DataObject $model): bool
    {
        return $this->validateModel($model);
    }

    /**
     * Core Combine::validate() semantics (aggregator all/any against the
     * expected TRUE/FALSE value) with snapshot-first child ordering
     */
    protected function validateModel(DataObject $model): bool
    {
        $conditions = $this->getConditions();
        if (!is_array($conditions) || $conditions === []) {
            return true;
        }
        $all = $this->getAggregator() === 'all';
        $expected = $this->getValue() === null ? true : (bool)$this->getValue();
        foreach ($this->sortForShortCircuit($conditions, $model) as $condition) {
            $validated = (bool)$condition->validate($model);
            if ($all && $validated !== $expected) {
                return false;
            }
            if (!$all && $validated === $expected) {
                return true;
            }
        }
        return $all;
    }

    /**
     * Stable partition: in-snapshot leaves first, hydration-requiring leaves
     * and nested combines (which may traverse/hydrate) last
     *
     * @param \Magento\Rule\Model\Condition\AbstractCondition[] $conditions
     * @return \Magento\Rule\Model\Condition\AbstractCondition[]
     */
    private function sortForShortCircuit(array $conditions, DataObject $model): array
    {
        $inSnapshot = [];
        $deferred = [];
        foreach ($conditions as $condition) {
            $attribute = $condition instanceof Combine ? null : $condition->getAttribute();
            if (is_string($attribute) && $attribute !== '' && $model->hasData($attribute)) {
                $inSnapshot[] = $condition;
            } else {
                $deferred[] = $condition;
            }
        }
        return array_merge($inSnapshot, $deferred);
    }

    /**
     * The "Related Entity" child option, offered by a root combine ONLY when
     * the RelationPool actually holds a relation sourced from that entity type
     * (docs/discovery/implementation/02: "wire only where relations exist —
     * Order/Quote/Customer, not Product"). Data-driven so a third-party
     * relation pack lights up its source entity's picker automatically.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    protected function relatedEntityChildOptions(RelationPool $relationPool, string $sourceEntityType): array
    {
        if ($relationPool->getBySourceEntityType($sourceEntityType) === []) {
            return [];
        }
        return [
            ['value' => RelatedEntityCombine::class, 'label' => __('Related record (exists / does not exist)')],
        ];
    }

    protected function getHydrationProvider(DataObject $model): ?HydrationProviderInterface
    {
        $provider = $model->getData(HydrationProviderInterface::KEY_PROVIDER);
        return $provider instanceof HydrationProviderInterface ? $provider : null;
    }

    /**
     * Hydrate the entity the validated model itself represents (its
     * convention keys), e.g. the order behind an order snapshot
     */
    protected function hydrateContextEntity(DataObject $model): ?DataObject
    {
        $provider = $this->getHydrationProvider($model);
        $entityType = (string)($model->getData(HydrationProviderInterface::KEY_ENTITY_TYPE) ?? '');
        $entityId = (int)($model->getData(HydrationProviderInterface::KEY_ENTITY_ID) ?? 0);
        if ($provider === null || $entityType === '' || $entityId <= 0) {
            return null;
        }
        $entity = $provider->getEntity(
            $entityType,
            $entityId,
            (bool)$model->getData(HydrationProviderInterface::KEY_FRESH)
        );
        if ($entity !== null) {
            $this->propagateHydrationKeys($model, $entity, $entityType, $entityId);
        }
        return $entity;
    }

    /**
     * Carry the hydration convention onto a derived model (hydrated related
     * entity, wrapped order item, ...) with adjusted entity coordinates
     */
    protected function propagateHydrationKeys(
        DataObject $from,
        DataObject $to,
        string $entityType,
        int $entityId
    ): void {
        $to->setData(HydrationProviderInterface::KEY_PROVIDER, $from->getData(HydrationProviderInterface::KEY_PROVIDER));
        $to->setData(HydrationProviderInterface::KEY_ENTITY_TYPE, $entityType);
        $to->setData(HydrationProviderInterface::KEY_ENTITY_ID, $entityId);
        $to->setData(HydrationProviderInterface::KEY_FRESH, (bool)$from->getData(HydrationProviderInterface::KEY_FRESH));
    }
}
