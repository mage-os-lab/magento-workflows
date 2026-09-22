<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Aggregation\BatchContextBuilder;
use MageOS\Workflows\Model\Aggregation\ItemProjector;

/**
 * Dry-run synthetic-batch fabrication (05): builds a batch trigger context with
 * N fabricated sample items so an aggregated workflow can be previewed without
 * an accumulator or any real events. A trace-level extension — the Walker still
 * walks an ordinary ExecutionContext whose trigger happens to be a batch shape.
 *
 * The fabricated items carry the workflow's projection fields (identity +
 * root-condition attributes, or the explicit projection) with placeholder
 * values, so digest rendering ({{ trigger.items|count }},
 * {{ trigger.items|pluck:'sku' }}, …) exercises the real formatter path.
 *
 * Because the samples are fabricated as members, the caller previews with
 * conditions omitted (membership is not re-applied to a synthetic batch).
 */
class SyntheticBatchFactory
{
    public const DEFAULT_SAMPLE_COUNT = 3;

    public function __construct(
        private readonly ItemProjector $itemProjector,
        private readonly BatchContextBuilder $batchContextBuilder
    ) {
    }

    /**
     * @return array<string, mixed> the {batch,count,window,overflow,items} trigger payload
     */
    public function fabricate(
        AggregationConfig $config,
        ?string $conditionsSerialized,
        int $sampleCount = self::DEFAULT_SAMPLE_COUNT
    ): array {
        $sampleCount = max(1, $sampleCount);
        $itemCap = $config->getItemCap();
        $fields = $this->itemProjector->fieldsFor($config->getProjection(), $conditionsSerialized);

        $items = [];
        $emit = min($sampleCount, $itemCap);
        for ($i = 1; $i <= $emit; $i++) {
            $items[] = $this->fabricateItem($fields, $i);
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->batchContextBuilder->build(
            $sampleCount,
            $items,
            ['from' => $now, 'to' => $now],
            $itemCap
        );
    }

    /**
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function fabricateItem(array $fields, int $index): array
    {
        $item = ['entity_id' => $index];
        foreach ($fields as $field) {
            if ($field === 'entity_id') {
                continue;
            }
            $item[$field] = sprintf('sample-%s-%d', $field, $index);
        }
        return $item;
    }
}
