<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObjectFactory;
use MageOS\Workflows\Model\Engine\FanOutExpander;
use MageOS\Workflows\Model\Relation\RelationContext;
use MageOS\Workflows\Model\Relation\RelationPool;
use MageOS\Workflows\Model\Rule\HydrationProviderInterface;

/**
 * Dry-run fan-out preview (04 stage 4): resolves a workflow's fan_out relation
 * live against a source entity and reports "would dispatch N executions (first
 * 3: …)" as a trace node — the read-only counterpart of {@see FanOutExpander},
 * with no dispatch and no side effects.
 *
 * The subject entity id is interpreted as the relation SOURCE (the entity the
 * trigger fires on), which is what fan-out resolves from — distinct from the
 * per-target walk the rest of the trace shows.
 */
class FanOutTracePreview
{
    private const SAMPLE_SIZE = 3;

    public function __construct(
        private readonly RelationPool $relationPool,
        private readonly RelationContext $relationContext,
        private readonly HydrationProviderInterface $hydrationProvider,
        private readonly DataObjectFactory $dataObjectFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array{
     *   relation: string,
     *   source_type?: string,
     *   target_type?: string,
     *   would_dispatch?: int,
     *   sample_target_ids?: int[],
     *   truncated?: bool,
     *   note?: string
     * }|null null when the workflow does not fan out
     */
    public function build(?string $fanOutJson, ?int $sourceEntityId): ?array
    {
        if ($fanOutJson === null || trim($fanOutJson) === '') {
            return null;
        }
        $config = json_decode($fanOutJson, true);
        $relationCode = is_array($config) ? ($config['relation'] ?? null) : null;
        if (!is_string($relationCode) || $relationCode === '') {
            return null;
        }
        if (!$this->relationPool->has($relationCode)) {
            return ['relation' => $relationCode, 'would_dispatch' => 0, 'note' => 'unknown_relation'];
        }

        $relation = $this->relationPool->get($relationCode);
        $node = [
            'relation' => $relationCode,
            'source_type' => $relation->getSourceEntityType(),
            'target_type' => $relation->getTargetEntityType(),
        ];

        if ($sourceEntityId === null || $sourceEntityId <= 0) {
            return $node + ['would_dispatch' => 0, 'note' => 'no_source_entity'];
        }

        $source = $this->hydrationProvider->getEntity($relation->getSourceEntityType(), $sourceEntityId, false);
        if ($source === null) {
            return $node + ['would_dispatch' => 0, 'note' => 'source_not_found'];
        }
        $source->setData(HydrationProviderInterface::KEY_ENTITY_TYPE, $relation->getSourceEntityType());
        if ((int) $source->getData('entity_id') <= 0) {
            $source->setData('entity_id', $sourceEntityId);
        }

        $ids = $this->relationContext->resolve($relationCode, $source);

        $cap = $this->effectiveCap(is_array($config) && is_numeric($config['cap'] ?? null) ? (int) $config['cap'] : null);
        $truncated = count($ids) > $cap;
        if ($truncated) {
            $ids = array_slice($ids, 0, $cap);
        }

        return $node + [
            'would_dispatch' => count($ids),
            'sample_target_ids' => array_slice($ids, 0, self::SAMPLE_SIZE),
            'truncated' => $truncated,
        ];
    }

    private function effectiveCap(?int $perWorkflowCap): int
    {
        $global = (int) $this->scopeConfig->getValue(FanOutExpander::CONFIG_FAN_OUT_CAP);
        if ($global <= 0) {
            $global = FanOutExpander::DEFAULT_FAN_OUT_CAP;
        }
        if ($perWorkflowCap === null || $perWorkflowCap <= 0) {
            return $global;
        }
        return min($perWorkflowCap, $global);
    }
}
