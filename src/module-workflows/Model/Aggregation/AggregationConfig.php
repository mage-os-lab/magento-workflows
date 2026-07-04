<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

/**
 * Immutable view over the mageos_workflow.aggregation JSON column (F8, 05).
 *
 * A non-null column is what makes a workflow "aggregated" — there is no
 * separate kind enum. The column shape:
 *
 *   {
 *     "mode": "collected" | "window",
 *     "window": {"type":"schedule","cron":"0 9 * * *","timezone":"America/New_York"}
 *             | {"type":"interval","duration":"PT1H"},
 *     "item_cap": 500,
 *     "min_items": 1,
 *     "projection": ["sku","name"],
 *     "aggregate_suppressed_events": false
 *   }
 *
 * `mode` distinguishes the two deliverables: `collected` is B1 (the scheduler
 * digest — accumulation happens in QueryRunner), `window` is B2 (the event
 * accumulator). The nested `window` policy (schedule/interval) governs when a
 * B2 batch flushes.
 */
class AggregationConfig
{
    public const MODE_COLLECTED = 'collected';
    public const MODE_WINDOW = 'window';

    public const WINDOW_SCHEDULE = 'schedule';
    public const WINDOW_INTERVAL = 'interval';

    public const DEFAULT_ITEM_CAP = 500;

    /**
     * Provisional default (05 §Compatibility): a window closing under the
     * minimum carries items to the next window (interval) or drops with a
     * debug log (schedule).
     */
    public const DEFAULT_MIN_ITEMS = 1;

    /**
     * @param array<string, mixed> $raw decoded aggregation config
     */
    private function __construct(
        private readonly array $raw
    ) {
    }

    /**
     * Parse the stored column. Null/empty (a per-entity workflow) returns null.
     *
     * @throws \InvalidArgumentException when the column is present but not a
     *         JSON object (fail loud rather than silently degrading a workflow
     *         to per-entity behaviour)
     */
    public static function fromJson(?string $json): ?self
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Aggregation config is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Aggregation config must decode to a JSON object');
        }
        return new self($decoded);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    public function getMode(): string
    {
        $mode = $this->raw['mode'] ?? self::MODE_WINDOW;
        return is_string($mode) && $mode !== '' ? $mode : self::MODE_WINDOW;
    }

    public function isCollected(): bool
    {
        return $this->getMode() === self::MODE_COLLECTED;
    }

    public function isWindow(): bool
    {
        return $this->getMode() === self::MODE_WINDOW;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWindow(): array
    {
        $window = $this->raw['window'] ?? [];
        return is_array($window) ? $window : [];
    }

    public function getWindowType(): ?string
    {
        $type = $this->getWindow()['type'] ?? null;
        return is_string($type) && $type !== '' ? $type : null;
    }

    public function getCron(): ?string
    {
        $cron = $this->getWindow()['cron'] ?? null;
        return is_string($cron) && trim($cron) !== '' ? trim($cron) : null;
    }

    public function getTimezone(): string
    {
        $tz = $this->getWindow()['timezone'] ?? null;
        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
    }

    public function getDuration(): ?string
    {
        $duration = $this->getWindow()['duration'] ?? null;
        return is_string($duration) && trim($duration) !== '' ? trim($duration) : null;
    }

    public function getItemCap(): int
    {
        $cap = $this->raw['item_cap'] ?? null;
        if (!is_numeric($cap) || (int) $cap <= 0) {
            return self::DEFAULT_ITEM_CAP;
        }
        return (int) $cap;
    }

    public function getMinItems(): int
    {
        $min = $this->raw['min_items'] ?? null;
        if (!is_numeric($min) || (int) $min < 1) {
            return self::DEFAULT_MIN_ITEMS;
        }
        return (int) $min;
    }

    /**
     * Explicit projection field list, or [] to signal the caller should derive
     * the default (identity fields + attributes named in the root conditions).
     *
     * @return string[]
     */
    public function getProjection(): array
    {
        $projection = $this->raw['projection'] ?? null;
        if (!is_array($projection)) {
            return [];
        }
        $fields = [];
        foreach ($projection as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }
        return array_values(array_unique($fields));
    }

    public function aggregateSuppressedEvents(): bool
    {
        return (bool) ($this->raw['aggregate_suppressed_events'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
