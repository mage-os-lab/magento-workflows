<?php
declare(strict_types=1);

namespace MageOS\WorkflowsCustomer\Model;

/**
 * Birthday anniversary arithmetic shared by BirthdayDetector (CUS-T3) and
 * CustomerBirthdayAggregateProvider (CUS-C4) — extracted so the two never
 * drift on the awkward edges (Feb-29, the year boundary).
 *
 * All dates are reduced to a calendar date at UTC midnight before any
 * comparison or difference, so results are whole-day integers free of DST or
 * time-of-day noise. A customer's `dob` is a date-only value; the "today" the
 * callers pass in is whatever single timezone they evaluate against (the
 * detector uses the store's configured default-scope timezone — see its
 * docblock).
 *
 * Feb-29 policy: a customer born on Feb 29 has no anniversary in a common
 * year, so we celebrate it on Feb 28 that year. In a leap year Feb 29 stands
 * on its own. This is applied consistently by both the day-count
 * (daysUntilNextBirthday) and the detector's SQL month/day match
 * (anniversaryMonthDaysFor).
 *
 * Final: value-object helper, no dependencies, safe to `new`.
 */
final class BirthdayCalculator
{
    /**
     * The date a customer's birthday falls on in a given calendar year,
     * mapping Feb 29 to Feb 28 in common (non-leap) years.
     */
    public function anniversaryInYear(\DateTimeInterface $dob, int $year): \DateTimeImmutable
    {
        $month = (int) $dob->format('n');
        $day = (int) $dob->format('j');
        if ($month === 2 && $day === 29 && !self::isLeapYear($year)) {
            $day = 28;
        }
        return $this->atMidnightUtc($year, $month, $day);
    }

    /**
     * The next anniversary on or after `today` (this year's if it hasn't
     * passed, otherwise next year's).
     */
    public function nextBirthday(\DateTimeInterface $dob, \DateTimeInterface $today): \DateTimeImmutable
    {
        $todayDate = $this->atMidnightUtc(
            (int) $today->format('Y'),
            (int) $today->format('n'),
            (int) $today->format('j')
        );
        $year = (int) $today->format('Y');
        $candidate = $this->anniversaryInYear($dob, $year);
        if ($candidate < $todayDate) {
            $candidate = $this->anniversaryInYear($dob, $year + 1);
        }
        return $candidate;
    }

    /**
     * Whole days from `today` until the next anniversary; 0 means the birthday
     * is today. Never negative.
     */
    public function daysUntilNextBirthday(\DateTimeInterface $dob, \DateTimeInterface $today): int
    {
        $todayDate = $this->atMidnightUtc(
            (int) $today->format('Y'),
            (int) $today->format('n'),
            (int) $today->format('j')
        );
        return (int) $todayDate->diff($this->nextBirthday($dob, $today))->days;
    }

    /**
     * The dob month/day strings ('m-d') whose anniversary falls on `$target`.
     * Normally the target's own month/day; when the target is Feb 28 of a
     * common year it ALSO covers Feb-29 births (which celebrate that day).
     *
     * @return string[] one or two 'm-d' values
     */
    public function anniversaryMonthDaysFor(\DateTimeInterface $target): array
    {
        $monthDay = $target->format('m-d');
        $days = [$monthDay];
        if ($monthDay === '02-28' && !self::isLeapYear((int) $target->format('Y'))) {
            $days[] = '02-29';
        }
        return $days;
    }

    /**
     * The customer's birth month, 1-12.
     */
    public function birthdayMonth(\DateTimeInterface $dob): int
    {
        return (int) $dob->format('n');
    }

    private function atMidnightUtc(int $year, int $month, int $day): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setDate($year, $month, $day)
            ->setTime(0, 0, 0);
    }

    private static function isLeapYear(int $year): bool
    {
        return ((int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setDate($year, 1, 1)
            ->format('L')) === 1;
    }
}
