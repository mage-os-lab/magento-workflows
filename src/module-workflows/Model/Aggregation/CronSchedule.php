<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Aggregation;

/**
 * Minimal standard 5-field cron evaluator (minute hour day-of-month month
 * day-of-week) used by the `schedule` window policy to find window boundaries
 * in an explicit store timezone.
 *
 * Pure and timezone-aware: previous()/next() find the nearest matching minute
 * at-or-before / strictly-after a reference instant, evaluated in the given
 * zone (the docs/14 timezone lesson, applied on day one). Field syntax:
 * `*`, lists `a,b`, ranges `a-b`, and steps `* / n` or `a-b/n`. Day-of-month
 * and day-of-week are OR'd only when both are restricted (standard cron), else
 * AND'd — matching Magento's cron semantics closely enough for window keys.
 */
class CronSchedule
{
    /**
     * Iteration budget for previous()/next(). The scan skips a whole DAY per
     * step when the day fields don't match and steps a minute at a time only
     * inside matching days, so the budget is sized in day-skips: a Feb-29-only
     * expression ('0 0 29 2 *') legitimately has its nearest match up to 8
     * years away (leap gap around a skipped century year), ~2930 day-skips,
     * plus at most two partial in-day minute scans (2 x 1440). 16000 covers
     * that with margin while keeping truly impossible expressions
     * ('0 0 31 4 *') failing in milliseconds, not after a half-million
     * minute probes as the old ±1-year minute-by-minute scan did.
     */
    private const LOOKAROUND_STEPS = 16000;

    /**
     * Latest matching minute at or before $ts (inclusive), as a unix timestamp.
     *
     * @throws \InvalidArgumentException on an unparseable expression
     */
    public function previous(int $ts, string $expression, \DateTimeZone $tz): int
    {
        $fields = $this->parse($expression);
        $cursor = $this->floorToMinute($ts, $tz);
        for ($i = 0; $i <= self::LOOKAROUND_STEPS; $i++) {
            $dt = (new \DateTimeImmutable('@' . $cursor))->setTimezone($tz);
            if (!$this->dayMatches($dt, $fields)) {
                // Skip to 23:59 of the previous day in the target zone.
                $cursor = $dt->setTime(0, 0)->modify('-1 minute')->getTimestamp();
                continue;
            }
            if ($this->timeMatches($dt, $fields)) {
                return $cursor;
            }
            $cursor -= 60;
        }
        throw new \InvalidArgumentException(
            sprintf('Cron expression "%s" matched no minute within the lookaround horizon', $expression)
        );
    }

    /**
     * Earliest matching minute strictly after $ts, as a unix timestamp.
     *
     * @throws \InvalidArgumentException on an unparseable expression
     */
    public function next(int $ts, string $expression, \DateTimeZone $tz): int
    {
        $fields = $this->parse($expression);
        $cursor = $this->floorToMinute($ts, $tz) + 60;
        for ($i = 0; $i <= self::LOOKAROUND_STEPS; $i++) {
            $dt = (new \DateTimeImmutable('@' . $cursor))->setTimezone($tz);
            if (!$this->dayMatches($dt, $fields)) {
                // Skip to 00:00 of the next day in the target zone.
                $cursor = $dt->setTime(0, 0)->modify('+1 day')->getTimestamp();
                continue;
            }
            if ($this->timeMatches($dt, $fields)) {
                return $cursor;
            }
            $cursor += 60;
        }
        throw new \InvalidArgumentException(
            sprintf('Cron expression "%s" matched no minute within the lookaround horizon', $expression)
        );
    }

    private function floorToMinute(int $ts, \DateTimeZone $tz): int
    {
        // Zero the seconds in the target zone by aligning to the minute.
        return $ts - ($ts % 60);
    }

    /**
     * @param array<int, int[]|bool> $fields
     */
    private function dayMatches(\DateTimeImmutable $dt, array $fields): bool
    {
        $dom = (int) $dt->format('j');
        $month = (int) $dt->format('n');
        $dow = (int) $dt->format('w'); // 0 (Sun) .. 6 (Sat)

        if (!in_array($month, $fields[3], true)) {
            return false;
        }

        $domRestricted = $fields[5] === false; // sentinel: was '*'?
        $dowRestricted = $fields[6] === false;
        $domMatch = in_array($dom, $fields[2], true);
        $dowMatch = in_array($dow, $fields[4], true);

        if ($domRestricted && $dowRestricted) {
            return $domMatch || $dowMatch;
        }
        return $domMatch && $dowMatch;
    }

    /**
     * @param array<int, int[]|bool> $fields
     */
    private function timeMatches(\DateTimeImmutable $dt, array $fields): bool
    {
        return in_array((int) $dt->format('i'), $fields[0], true)
            && in_array((int) $dt->format('G'), $fields[1], true);
    }

    /**
     * @return array<int, int[]|bool> [minutes, hours, dom, months, dow,
     *         domWasStar, dowWasStar]
     */
    private function parse(string $expression): array
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(sprintf('Cron expression "%s" must have 5 fields', $expression));
        }
        return [
            0 => $this->expand($parts[0], 0, 59),
            1 => $this->expand($parts[1], 0, 23),
            2 => $this->expand($parts[2], 1, 31),
            3 => $this->expand($parts[3], 1, 12),
            4 => $this->expand($this->normalizeDow($parts[4]), 0, 6),
            5 => $parts[2] === '*', // dom was star
            6 => $parts[4] === '*', // dow was star
        ];
    }

    private function normalizeDow(string $field): string
    {
        // Accept 7 as Sunday.
        return str_replace('7', '0', $field);
    }

    /**
     * @return int[]
     */
    private function expand(string $field, int $min, int $max): array
    {
        $values = [];
        foreach (explode(',', $field) as $part) {
            $step = 1;
            $range = $part;
            if (str_contains($part, '/')) {
                [$range, $stepStr] = explode('/', $part, 2);
                $step = max(1, (int) $stepStr);
            }
            if ($range === '*') {
                $lo = $min;
                $hi = $max;
            } elseif (str_contains($range, '-')) {
                [$loStr, $hiStr] = explode('-', $range, 2);
                $lo = (int) $loStr;
                $hi = (int) $hiStr;
            } else {
                $lo = (int) $range;
                $hi = (int) $range;
            }
            if ($lo < $min || $hi > $max || $lo > $hi) {
                throw new \InvalidArgumentException(sprintf('Cron field "%s" is out of range %d-%d', $field, $min, $max));
            }
            for ($v = $lo; $v <= $hi; $v += $step) {
                $values[$v] = true;
            }
        }
        if ($values === []) {
            throw new \InvalidArgumentException(sprintf('Cron field "%s" is empty', $field));
        }
        return array_keys($values);
    }
}
