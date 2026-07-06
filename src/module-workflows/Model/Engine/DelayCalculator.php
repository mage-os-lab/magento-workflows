<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Engine;

/**
 * Pure resume-time arithmetic for delay and wait steps (docs/16-capability-roadmap.md
 * wave 3). Plain ISO-8601 durations keep the original absolute-UTC semantics;
 * the v2 extras (business_days, at) are computed in the store's timezone
 * because "2 business days" and "at 09:00" are merchant-local concepts.
 *
 * The result is always clamped to now + maxDays so a fat-fingered "P1Y"
 * cannot park an execution silently for a year (docs/14, delay ceiling).
 */
class DelayCalculator
{
    /**
     * @param array{duration?: string, business_days?: bool, at?: string} $config
     * @return array{0: \DateTimeImmutable, 1: bool} resume time (UTC) and whether the ceiling clamped it
     * @throws \InvalidArgumentException when the duration is not ISO-8601
     */
    public function computeResumeAt(
        \DateTimeImmutable $nowUtc,
        array $config,
        string $timezone,
        int $maxDays
    ): array {
        $duration = (string) ($config['duration'] ?? 'PT0S');
        try {
            $interval = new \DateInterval($duration);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                sprintf('Duration "%s" is not ISO-8601', $duration),
                0,
                $e
            );
        }

        $businessDays = (bool) ($config['business_days'] ?? false);
        $at = $config['at'] ?? null;

        try {
            $tz = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }

        if (!$businessDays && $at === null) {
            // v1 semantics untouched: absolute UTC arithmetic
            $resume = $nowUtc->add($interval);
        } else {
            $local = $nowUtc->setTimezone($tz);

            if ($businessDays) {
                // Calendar parts first (months/years stay calendar), then the
                // day component counted Mon-Fri, then the time components.
                if ($interval->y > 0 || $interval->m > 0) {
                    $local = $local->add(new \DateInterval(sprintf('P%dY%dM', $interval->y, $interval->m)));
                }
                $local = $this->addBusinessDays($local, $interval->d);
                if ($interval->h > 0 || $interval->i > 0 || $interval->s > 0) {
                    $local = $local->add(
                        new \DateInterval(sprintf('PT%dH%dM%dS', $interval->h, $interval->i, $interval->s))
                    );
                }
            } else {
                $local = $local->add($interval);
            }

            if (is_string($at) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at, $m)) {
                $candidate = $local->setTime((int) $m[1], (int) $m[2], 0);
                if ($candidate <= $local) {
                    $candidate = $candidate->add(new \DateInterval('P1D'));
                }
                if ($businessDays) {
                    $candidate = $this->skipWeekend($candidate);
                }
                $local = $candidate;
            }

            $resume = $local->setTimezone(new \DateTimeZone('UTC'));
        }

        if ($maxDays > 0) {
            $ceiling = $nowUtc->add(new \DateInterval(sprintf('P%dD', $maxDays)));
            if ($resume > $ceiling) {
                return [$ceiling, true];
            }
        }

        return [$resume, false];
    }

    private function addBusinessDays(\DateTimeImmutable $from, int $days): \DateTimeImmutable
    {
        $current = $from;
        $oneDay = new \DateInterval('P1D');
        for ($i = 0; $i < $days; $i++) {
            do {
                $current = $current->add($oneDay);
            } while ($this->isWeekend($current));
        }
        return $current;
    }

    private function skipWeekend(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $oneDay = new \DateInterval('P1D');
        while ($this->isWeekend($date)) {
            $date = $date->add($oneDay);
        }
        return $date;
    }

    private function isWeekend(\DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }
}
