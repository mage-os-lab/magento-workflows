<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Test\Unit\Model;

use MageOS\WorkflowsCustomer\Model\BirthdayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic anniversary math shared by the detector (CUS-T3) and the
 * days_until_birthday aggregate (CUS-C4): today/tomorrow, the year wrap-around
 * (a January birthday seen in late December is next year's), and the Feb-29
 * policy (celebrated Feb 28 in common years, Feb 29 in leap years) — plus the
 * SQL month/day set the detector matches on.
 */
class BirthdayCalculatorTest extends TestCase
{
    private function utc(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    private function calculator(): BirthdayCalculator
    {
        return new BirthdayCalculator();
    }

    public function testBirthdayTodayIsZeroDays(): void
    {
        $this->assertSame(
            0,
            $this->calculator()->daysUntilNextBirthday($this->utc('1990-06-15'), $this->utc('2026-06-15'))
        );
    }

    public function testBirthdayTomorrowIsOneDay(): void
    {
        $this->assertSame(
            1,
            $this->calculator()->daysUntilNextBirthday($this->utc('1990-06-16'), $this->utc('2026-06-15'))
        );
    }

    public function testTimeOfDayIsIgnored(): void
    {
        // "today" carries a late time; the birthday is still today (0 days).
        $this->assertSame(
            0,
            $this->calculator()->daysUntilNextBirthday(
                $this->utc('1990-06-15'),
                $this->utc('2026-06-15 23:59:59')
            )
        );
    }

    public function testYearWrapAroundJanuaryBirthdaySeenInDecember(): void
    {
        // Dec 25 -> the Jan 1 birthday is 7 days out, and it is NEXT year's.
        $calc = $this->calculator();
        $this->assertSame(
            7,
            $calc->daysUntilNextBirthday($this->utc('1985-01-01'), $this->utc('2026-12-25'))
        );
        $this->assertSame(
            '2027-01-01',
            $calc->nextBirthday($this->utc('1985-01-01'), $this->utc('2026-12-25'))->format('Y-m-d')
        );
    }

    public function testBirthdayAlreadyPassedThisYearRollsToNextYear(): void
    {
        // Jan 1 today, birthday was "yesterday" (Dec 31) -> 364/365 days out, next year.
        $next = $this->calculator()->nextBirthday($this->utc('1985-12-31'), $this->utc('2026-01-01'));
        $this->assertSame('2026-12-31', $next->format('Y-m-d'));
    }

    public function testFeb29CelebratedOnFeb28InCommonYear(): void
    {
        // 2026 is not a leap year: a Feb-29 birthday is celebrated Feb 28.
        $calc = $this->calculator();
        $this->assertSame(
            7,
            $calc->daysUntilNextBirthday($this->utc('2000-02-29'), $this->utc('2026-02-21'))
        );
        $this->assertSame(
            '2026-02-28',
            $calc->nextBirthday($this->utc('2000-02-29'), $this->utc('2026-02-21'))->format('Y-m-d')
        );
    }

    public function testFeb29StandsOnItsOwnInLeapYear(): void
    {
        // 2028 is a leap year: the Feb-29 birthday is Feb 29, 8 days after Feb 21.
        $calc = $this->calculator();
        $this->assertSame(
            8,
            $calc->daysUntilNextBirthday($this->utc('2000-02-29'), $this->utc('2028-02-21'))
        );
        $this->assertSame(
            '2028-02-29',
            $calc->nextBirthday($this->utc('2000-02-29'), $this->utc('2028-02-21'))->format('Y-m-d')
        );
    }

    public function testAnniversaryMonthDaysCommonYearFeb28AlsoCoversFeb29(): void
    {
        // Target Feb 28 in a common year: Feb-29 births celebrate that day too.
        $this->assertSame(
            ['02-28', '02-29'],
            $this->calculator()->anniversaryMonthDaysFor($this->utc('2026-02-28'))
        );
    }

    public function testAnniversaryMonthDaysLeapYearFeb28IsFeb28Only(): void
    {
        // Target Feb 28 in a leap year: Feb-29 births are a distinct day (Feb 29).
        $this->assertSame(
            ['02-28'],
            $this->calculator()->anniversaryMonthDaysFor($this->utc('2028-02-28'))
        );
    }

    public function testAnniversaryMonthDaysLeapYearFeb29(): void
    {
        $this->assertSame(
            ['02-29'],
            $this->calculator()->anniversaryMonthDaysFor($this->utc('2028-02-29'))
        );
    }

    public function testAnniversaryMonthDaysOrdinaryDate(): void
    {
        $this->assertSame(
            ['03-15'],
            $this->calculator()->anniversaryMonthDaysFor($this->utc('2026-03-15'))
        );
    }

    public function testBirthdayMonth(): void
    {
        $this->assertSame(7, $this->calculator()->birthdayMonth($this->utc('1990-07-09')));
        $this->assertSame(2, $this->calculator()->birthdayMonth($this->utc('2000-02-29')));
    }
}
