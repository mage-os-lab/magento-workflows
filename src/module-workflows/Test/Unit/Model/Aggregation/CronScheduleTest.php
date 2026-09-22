<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Aggregation;

use MageOS\Workflows\Model\Aggregation\CronSchedule;
use PHPUnit\Framework\TestCase;

class CronScheduleTest extends TestCase
{
    private CronSchedule $cron;

    public function setUp(): void
    {
        $this->cron = new CronSchedule();
    }

    private function ts(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }

    private function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public function testDailyPreviousAndNextInStoreTimezone(): void
    {
        $tz = new \DateTimeZone('America/New_York');
        // 2026-07-04 11:00 EDT = 15:00 UTC; daily fire is 09:00 EDT = 13:00 UTC.
        $now = $this->ts('2026-07-04 15:00:00');

        $prev = $this->cron->previous($now, '0 9 * * *', $tz);
        $next = $this->cron->next($now, '0 9 * * *', $tz);

        $this->assertSame('2026-07-04 13:00:00', $this->utc($prev));
        $this->assertSame('2026-07-05 13:00:00', $this->utc($next));
    }

    public function testTimezoneShiftsTheFireInstant(): void
    {
        // Same cron, different zone => different UTC instant (the docs/14 lesson).
        $ny = new \DateTimeZone('America/New_York');
        $utc = new \DateTimeZone('UTC');
        $now = $this->ts('2026-07-04 15:00:00');

        $prevNy = $this->cron->previous($now, '0 9 * * *', $ny);
        $prevUtc = $this->cron->previous($now, '0 9 * * *', $utc);

        $this->assertSame('2026-07-04 13:00:00', $this->utc($prevNy)); // 09:00 EDT
        $this->assertSame('2026-07-04 09:00:00', $this->utc($prevUtc)); // 09:00 UTC
    }

    public function testHourly(): void
    {
        $tz = new \DateTimeZone('UTC');
        $now = $this->ts('2026-07-04 15:30:00');

        $this->assertSame('2026-07-04 15:00:00', $this->utc($this->cron->previous($now, '0 * * * *', $tz)));
        $this->assertSame('2026-07-04 16:00:00', $this->utc($this->cron->next($now, '0 * * * *', $tz)));
    }

    public function testStepMinutes(): void
    {
        $tz = new \DateTimeZone('UTC');
        $now = $this->ts('2026-07-04 15:07:00');

        $this->assertSame('2026-07-04 15:00:00', $this->utc($this->cron->previous($now, '*/15 * * * *', $tz)));
        $this->assertSame('2026-07-04 15:15:00', $this->utc($this->cron->next($now, '*/15 * * * *', $tz)));
    }

    public function testPreviousIsInclusiveOnAnExactMatch(): void
    {
        $tz = new \DateTimeZone('UTC');
        $now = $this->ts('2026-07-04 09:00:00');

        $this->assertSame('2026-07-04 09:00:00', $this->utc($this->cron->previous($now, '0 9 * * *', $tz)));
        // next is strictly after
        $this->assertSame('2026-07-05 09:00:00', $this->utc($this->cron->next($now, '0 9 * * *', $tz)));
    }

    public function testInvalidExpressionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cron->previous($this->ts('2026-07-04 09:00:00'), '0 9 * *', new \DateTimeZone('UTC'));
    }

    public function testDayOfWeekRestriction(): void
    {
        $tz = new \DateTimeZone('UTC');
        // 2026-07-04 is a Saturday; Mondays only at 09:00. Previous Monday is
        // 2026-06-29.
        $now = $this->ts('2026-07-04 12:00:00');

        $this->assertSame('2026-06-29 09:00:00', $this->utc($this->cron->previous($now, '0 9 * * 1', $tz)));
        $this->assertSame('2026-07-06 09:00:00', $this->utc($this->cron->next($now, '0 9 * * 1', $tz)));
    }
}
