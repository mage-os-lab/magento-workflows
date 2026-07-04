<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use MageOS\Workflows\Model\Aggregation\AggregationConfig;
use MageOS\Workflows\Model\Aggregation\CronSchedule;
use MageOS\Workflows\Model\Aggregation\WindowKeyCalculator;
use PHPUnit\Framework\TestCase;

class WindowKeyCalculatorTest extends TestCase
{
    private WindowKeyCalculator $calculator;

    public function setUp(): void
    {
        $this->calculator = new WindowKeyCalculator(new CronSchedule());
    }

    private function ts(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }

    public function testScheduleWindowKeyIsWindowStartInDeclaredTimezone(): void
    {
        $config = AggregationConfig::fromJson(
            '{"mode":"window","window":{"type":"schedule","cron":"0 9 * * *","timezone":"America/New_York"}}'
        );
        $now = $this->ts('2026-07-04 15:00:00'); // 11:00 EDT

        $result = $this->calculator->resolve($config, $now);

        // Window-start instant in the declared timezone, ISO-8601 with offset.
        $this->assertSame('2026-07-04T09:00:00-04:00', $result['window_key']);
        // flush_due is the next cron fire, stored UTC.
        $this->assertSame('2026-07-05 13:00:00', $result['flush_due_at']);
    }

    public function testScheduleWindowKeyIsStableAcrossTheWholeWindow(): void
    {
        $config = AggregationConfig::fromJson(
            '{"mode":"window","window":{"type":"schedule","cron":"0 9 * * *","timezone":"America/New_York"}}'
        );

        // Two different instants within the same daily window compute the same
        // key — so every event converges on one batch row.
        $a = $this->calculator->resolve($config, $this->ts('2026-07-04 14:00:00'));
        $b = $this->calculator->resolve($config, $this->ts('2026-07-04 20:00:00'));

        $this->assertSame($a['window_key'], $b['window_key']);
    }

    public function testIntervalWindowKeyIsOpeningInstantTruncatedToSeconds(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","window":{"type":"interval","duration":"PT1H"}}');
        $now = $this->ts('2026-07-04 13:22:07');

        $result = $this->calculator->resolve($config, $now);

        $this->assertSame('2026-07-04T13:22:07Z', $result['window_key']);
        $this->assertSame('2026-07-04 14:22:07', $result['flush_due_at']);
    }

    public function testIntervalDurationMinutes(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","window":{"type":"interval","duration":"PT30M"}}');
        $now = $this->ts('2026-07-04 13:00:00');

        $result = $this->calculator->resolve($config, $now);

        $this->assertSame('2026-07-04 13:30:00', $result['flush_due_at']);
    }

    public function testInvalidWindowPolicyThrows(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","window":{"type":"quiet"}}');

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->resolve($config, $this->ts('2026-07-04 13:00:00'));
    }

    public function testScheduleWithoutCronThrows(): void
    {
        $config = AggregationConfig::fromJson('{"mode":"window","window":{"type":"schedule","timezone":"UTC"}}');

        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->resolve($config, $this->ts('2026-07-04 13:00:00'));
    }
}
