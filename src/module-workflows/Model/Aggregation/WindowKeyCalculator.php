<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

/**
 * Computes the flush-idempotency key (`window_key`) and `flush_due_at` for a
 * B2 batch, per the window policy. The key MUST be deterministic — it backs
 * UNIQUE(workflow_id, window_key) and is the flush idempotency anchor:
 *
 *   - schedule → the window-START instant in the declared timezone, ISO-8601
 *     with offset ("2026-07-04T09:00:00-04:00"); flush_due is the NEXT cron
 *     fire. Every event in the same window computes the same key, so the
 *     accumulator naturally converges on one batch row.
 *   - interval → the OPENING event's UTC timestamp truncated to seconds
 *     ("2026-07-04T13:22:07Z"); flush_due is opening + duration. Only the
 *     opening event mints the key; later events append to the open batch.
 *
 * All DB timestamps (`flush_due_at`) are UTC 'Y-m-d H:i:s'.
 */
class WindowKeyCalculator
{
    public function __construct(
        private readonly CronSchedule $cronSchedule
    ) {
    }

    /**
     * @return array{window_key: string, flush_due_at: string}
     * @throws \InvalidArgumentException on an unusable window policy
     */
    public function resolve(AggregationConfig $config, int $nowTs): array
    {
        return match ($config->getWindowType()) {
            AggregationConfig::WINDOW_SCHEDULE => $this->resolveSchedule($config, $nowTs),
            AggregationConfig::WINDOW_INTERVAL => $this->resolveInterval($config, $nowTs),
            default => throw new \InvalidArgumentException(
                'Aggregation window policy must be "schedule" or "interval"'
            ),
        };
    }

    /**
     * @return array{window_key: string, flush_due_at: string}
     */
    private function resolveSchedule(AggregationConfig $config, int $nowTs): array
    {
        $cron = $config->getCron();
        if ($cron === null) {
            throw new \InvalidArgumentException('Schedule window policy requires a cron expression');
        }
        $tz = new \DateTimeZone($config->getTimezone());

        $windowStart = $this->cronSchedule->previous($nowTs, $cron, $tz);
        $flushDue = $this->cronSchedule->next($nowTs, $cron, $tz);

        return [
            'window_key' => (new \DateTimeImmutable('@' . $windowStart))->setTimezone($tz)->format('c'),
            'flush_due_at' => gmdate('Y-m-d H:i:s', $flushDue),
        ];
    }

    /**
     * @return array{window_key: string, flush_due_at: string}
     */
    private function resolveInterval(AggregationConfig $config, int $nowTs): array
    {
        $duration = $config->getDuration();
        if ($duration === null) {
            throw new \InvalidArgumentException('Interval window policy requires a duration');
        }
        try {
            $interval = new \DateInterval($duration);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                sprintf('Interval window duration "%s" is not a valid ISO-8601 duration', $duration),
                0,
                $e
            );
        }
        $flushDue = (new \DateTimeImmutable('@' . $nowTs))->add($interval)->getTimestamp();

        return [
            'window_key' => gmdate('Y-m-d\TH:i:s\Z', $nowTs),
            'flush_due_at' => gmdate('Y-m-d H:i:s', $flushDue),
        ];
    }
}
