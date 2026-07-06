<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\Engine;

use MageOS\Workflows\Model\Engine\DelayCalculator;
use PHPUnit\Framework\TestCase;

class DelayCalculatorTest extends TestCase
{
    private DelayCalculator $calculator;

    public function setUp(): void
    {
        $this->calculator = new DelayCalculator();
    }

    public function testPlainDurationOneHourFromFixedTime(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'PT1H'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        $this->assertSame('2026-07-01 11:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testPlainTwoDayDurationKeepsUtcArithmetic(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P2D'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'America/New_York', 0);

        $this->assertSame('2026-07-03 10:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testBusinessDaysTwoDaysFromFriday(): void
    {
        // Friday 2026-07-03 10:00 UTC in UTC timezone, add 2 business days
        // Fri + 1 = Sat (skip) + 1 = Sun (skip) + 1 = Mon + 1 = Tue
        $nowUtc = new \DateTimeImmutable('2026-07-03 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P2D', 'business_days' => true];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        // Should land on Tuesday 2026-07-07
        $this->assertSame('2026-07-07 10:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testBusinessDaysWithTimeComponent(): void
    {
        // Thursday 2026-07-02 10:00 UTC
        // P1DT2H with business_days: add 1 business day (Fri), then add 2 hours
        $nowUtc = new \DateTimeImmutable('2026-07-02 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P1DT2H', 'business_days' => true];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        $this->assertSame('2026-07-03 12:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testAtTimeWithTimezoneConversion(): void
    {
        // Now: 2026-07-01 10:00:00 UTC
        // Add PT1H: 2026-07-01 11:00:00 UTC
        // In America/New_York (UTC-4 in July): 2026-07-01 07:00:00
        // Find next 09:00 in that timezone: 2026-07-01 09:00:00
        // But candidate (09:00) > local (07:00), so use it
        // Convert back to UTC: 2026-07-01 13:00:00 UTC
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'PT1H', 'at' => '09:00'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'America/New_York', 0);

        $this->assertSame('2026-07-01 13:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testAtTimeRollsForwardToNextDay(): void
    {
        // Now: 2026-07-01 22:00:00 UTC
        // Add PT1H: 2026-07-01 23:00:00 UTC
        // In America/New_York (UTC-4 in July): 2026-07-01 19:00:00
        // Find next 09:00: 2026-07-02 09:00:00 (must be after current time)
        // Convert back to UTC: 2026-07-02 13:00:00 UTC
        $nowUtc = new \DateTimeImmutable('2026-07-01 22:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'PT1H', 'at' => '09:00'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'America/New_York', 0);

        $this->assertSame('2026-07-02 13:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testAtWithBusinessDaysSkipsWeekend(): void
    {
        // Friday 2026-07-03 22:00:00 UTC
        // Add PT1H: 2026-07-03 23:00:00 UTC
        // In UTC: 2026-07-03 23:00:00
        // Find next 09:00: 2026-07-04 09:00:00 (Saturday)
        // Skip weekend: 2026-07-06 09:00:00 (Monday)
        $nowUtc = new \DateTimeImmutable('2026-07-03 22:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'PT1H', 'at' => '09:00', 'business_days' => true];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        $this->assertSame('2026-07-06 09:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testInvalidDurationThrowsException(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'NOPE'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration "NOPE" is not ISO-8601');

        $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);
    }

    public function testClampingAtMaxDays(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P400D'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 365);

        // Resume should be clamped to now + 365D
        $ceiling = $nowUtc->add(new \DateInterval('P365D'));
        $this->assertSame($ceiling->format('Y-m-d H:i:s'), $resume->format('Y-m-d H:i:s'));
        $this->assertTrue($clamped);
    }

    public function testMaxDaysZeroDisablesClamping(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P400D'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        // Should pass through without clamping
        $expected = $nowUtc->add(new \DateInterval('P400D'));
        $this->assertSame($expected->format('Y-m-d H:i:s'), $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testInvalidTimezoneStringFallsBackToUtc(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'PT1H'];

        // Should not throw, just use UTC instead
        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'Invalid/Timezone', 0);

        $this->assertSame('2026-07-01 11:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testDefaultDurationIfNotProvided(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = [];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        // Default is PT0S (no delay)
        $this->assertSame('2026-07-01 10:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testBusinessDaysWithMonthAndYearComponent(): void
    {
        // Test that months/years are applied first, then business days
        $nowUtc = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P1Y1M2D', 'business_days' => true];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        // P1Y1M takes us to 2027-02-01 10:00:00 (Thursday)
        // Then add 2 business days: Thu + 1 = Fri + 1 = Mon
        // Result: 2027-02-03 10:00:00 (Monday)
        $this->assertSame('2027-02-03 10:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testAtWithInvalidFormatIgnored(): void
    {
        // Invalid time format should be silently ignored, plain duration applies
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'PT1H', 'at' => 'invalid'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 0);

        // Should behave like plain duration since 'at' is invalid
        $this->assertSame('2026-07-01 11:00:00', $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }

    public function testNoClampingWhenWithinLimit(): void
    {
        $nowUtc = new \DateTimeImmutable('2026-07-01 10:00:00', new \DateTimeZone('UTC'));
        $config = ['duration' => 'P100D'];

        [$resume, $clamped] = $this->calculator->computeResumeAt($nowUtc, $config, 'UTC', 365);

        // Should NOT be clamped since P100D is less than maxDays 365
        $expected = $nowUtc->add(new \DateInterval('P100D'));
        $this->assertSame($expected->format('Y-m-d H:i:s'), $resume->format('Y-m-d H:i:s'));
        $this->assertFalse($clamped);
    }
}
