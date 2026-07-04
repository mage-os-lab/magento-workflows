<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

/**
 * Builds the batch trigger context shape shared by B1 (collected mode) and B2
 * (accumulator flush), documented in docs/04:
 *
 *   trigger = {batch:true, count, window:{from,to}, overflow, items:[…]}
 *
 * `count` is always the true match count; `overflow` is true when it exceeds
 * the item cap so `items` is elided rather than silently truncated — every cap
 * surfaces.
 */
class BatchContextBuilder
{
    /**
     * @param array<int, array<string, mixed>> $cappedItems already capped to item_cap
     * @param array{from?: ?string, to?: ?string} $window
     * @return array<string, mixed>
     */
    public function build(int $count, array $cappedItems, array $window, int $itemCap): array
    {
        return [
            'batch' => true,
            'count' => $count,
            'overflow' => $count > $itemCap,
            'window' => [
                'from' => $window['from'] ?? null,
                'to' => $window['to'] ?? null,
            ],
            'items' => array_values($cappedItems),
        ];
    }
}
