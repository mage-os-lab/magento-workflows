<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
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
    private const LOOKAROUND_MINUTES = 366 * 24 * 60;

    /**
     * Latest matching minute at or before $ts (inclusive), as a unix timestamp.
     *
     * @throws \InvalidArgumentException on an unparseable expression
     */
    public function previous(int $ts, string $expression, \DateTimeZone $tz): int
    {
        $fields = $this->parse($expression);
        $cursor = $this->floorToMinute($ts, $tz);
        for ($i = 0; $i <= self::LOOKAROUND_MINUTES; $i++) {
            if ($this->matches($cursor, $fields, $tz)) {
                return $cursor;
            }
            $cursor -= 60;
        }
        throw new \InvalidArgumentException(sprintf('Cron expression "%s" matched no minute in a year', $expression));
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
        for ($i = 0; $i <= self::LOOKAROUND_MINUTES; $i++) {
            if ($this->matches($cursor, $fields, $tz)) {
                return $cursor;
            }
            $cursor += 60;
        }
        throw new \InvalidArgumentException(sprintf('Cron expression "%s" matched no minute in a year', $expression));
    }

    private function floorToMinute(int $ts, \DateTimeZone $tz): int
    {
        // Zero the seconds in the target zone by aligning to the minute.
        return $ts - ($ts % 60);
    }

    /**
     * @param array<int, int[]> $fields
     */
    private function matches(int $ts, array $fields, \DateTimeZone $tz): bool
    {
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $minute = (int) $dt->format('i');
        $hour = (int) $dt->format('G');
        $dom = (int) $dt->format('j');
        $month = (int) $dt->format('n');
        $dow = (int) $dt->format('w'); // 0 (Sun) .. 6 (Sat)

        if (!in_array($minute, $fields[0], true)) {
            return false;
        }
        if (!in_array($hour, $fields[1], true)) {
            return false;
        }
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
