<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Cron;

/**
 * Standalone-runner shim for dragonmantank/cron-expression's
 * Cron\CronExpression (the library RunScheduledWorkflows evaluates
 * trigger_ref with).
 *
 * NOTE: the standalone runner's shim autoloader only serves Magento\ and
 * Psr\Log\ prefixes, so this file is loaded via a class_exists()-guarded
 * require_once at the top of the test files that need it. When the real
 * library is installed (real Magento / Composer environments) the guard
 * ensures this shim is never loaded.
 *
 * Faithful to the surface the scheduler uses:
 * - __construct(string $expression) throws \InvalidArgumentException for
 *   anything that is not a valid 5-field cron expression;
 * - isDue($currentTime) matches minute/hour/day-of-month/month/day-of-week
 *   against the given \DateTimeInterface IN THAT DATETIME'S OWN TIMEZONE
 *   (which is exactly the store-timezone contract under test). Supports
 *   "*", numbers, comma lists, ranges (a-b) and steps (/n); 0 and 7 both
 *   mean Sunday; standard cron day-of-month/day-of-week union semantics.
 */
class CronExpression
{
    /** @var array<int, array{0:int, 1:int}> per-field [min, max] */
    private const BOUNDS = [
        [0, 59], // minute
        [0, 23], // hour
        [1, 31], // day of month
        [1, 12], // month
        [0, 7],  // day of week (0 and 7 = Sunday)
    ];

    /**
     * @var array<int, array<int, true>|null> allowed values per field; null = "*" (unrestricted)
     */
    private array $fields = [];

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(
                sprintf('%s is not a valid CRON expression', $expression)
            );
        }
        foreach (array_values($parts) as $position => $part) {
            $this->fields[$position] = $this->parseField($part, $position, $expression);
        }
    }

    /**
     * @param string|\DateTimeInterface $currentTime
     * @param string|null $timeZone
     */
    public function isDue($currentTime = 'now', $timeZone = null): bool
    {
        if (!$currentTime instanceof \DateTimeInterface) {
            $currentTime = new \DateTimeImmutable(
                (string) $currentTime,
                $timeZone !== null ? new \DateTimeZone($timeZone) : null
            );
        }

        $minuteOk = $this->matches(0, (int) $currentTime->format('i'));
        $hourOk = $this->matches(1, (int) $currentTime->format('G'));
        $monthOk = $this->matches(3, (int) $currentTime->format('n'));

        $domRestricted = $this->fields[2] !== null;
        $dowRestricted = $this->fields[4] !== null;
        $domOk = $this->matches(2, (int) $currentTime->format('j'));
        $dowOk = $this->matches(4, (int) $currentTime->format('w'));
        // Standard cron quirk: when BOTH day fields are restricted, a date
        // matches if EITHER does; otherwise both (trivially) must match.
        $dayOk = ($domRestricted && $dowRestricted) ? ($domOk || $dowOk) : ($domOk && $dowOk);

        return $minuteOk && $hourOk && $monthOk && $dayOk;
    }

    private function matches(int $position, int $value): bool
    {
        $allowed = $this->fields[$position];
        if ($allowed === null) {
            return true;
        }
        if ($position === 4 && $value === 0 && isset($allowed[7])) {
            return true; // 7 = Sunday = 0
        }
        return isset($allowed[$value]);
    }

    /**
     * @return array<int, true>|null
     */
    private function parseField(string $field, int $position, string $expression): ?array
    {
        if ($field === '*') {
            return null;
        }

        [$min, $max] = self::BOUNDS[$position];
        $allowed = [];

        foreach (explode(',', $field) as $atom) {
            if ($atom === '' || !preg_match('#^(\*|\d+(?:-\d+)?)(/\d+)?$#', $atom, $m)) {
                throw new \InvalidArgumentException(
                    sprintf('%s is not a valid CRON expression', $expression)
                );
            }

            $step = isset($m[2]) && $m[2] !== '' ? (int) substr($m[2], 1) : 1;
            if ($step < 1) {
                throw new \InvalidArgumentException(
                    sprintf('%s is not a valid CRON expression', $expression)
                );
            }

            $base = $m[1];
            if ($base === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($base, '-')) {
                [$from, $to] = array_map('intval', explode('-', $base, 2));
            } else {
                $from = (int) $base;
                // "n/step" means "start at n, every step until max"
                $to = (isset($m[2]) && $m[2] !== '') ? $max : (int) $base;
            }

            if ($from < $min || $to > $max || $from > $to) {
                throw new \InvalidArgumentException(
                    sprintf('%s is not a valid CRON expression', $expression)
                );
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $allowed[$value] = true;
            }
        }

        return $allowed;
    }
}
