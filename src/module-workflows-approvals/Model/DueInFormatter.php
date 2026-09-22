<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Model;

/**
 * Renders a task's due_at (§3 — the step's resume_at, the SLA clock) as
 * merchant-facing time-remaining / overdue text for the grid's "due-in" column
 * and the decision view (docs/discovery/approval-gate.md §6). Pure string
 * formatting against an injected "now" so it is deterministic under test.
 */
class DueInFormatter
{
    private const UNITS = [
        ['seconds' => 86400, 'label' => 'd'],
        ['seconds' => 3600, 'label' => 'h'],
        ['seconds' => 60, 'label' => 'm'],
    ];

    /**
     * @param string|null $dueAt UTC 'Y-m-d H:i:s', or null when the task carries none
     */
    public function format(?string $dueAt, ?\DateTimeImmutable $now = null): string
    {
        if ($dueAt === null || trim($dueAt) === '') {
            return '—';
        }
        try {
            $due = new \DateTimeImmutable($dueAt, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return '—';
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $diffSeconds = $due->getTimestamp() - $now->getTimestamp();

        if ($diffSeconds <= 0) {
            return sprintf('Overdue by %s', $this->humanize(abs($diffSeconds)));
        }
        return sprintf('Due in %s', $this->humanize($diffSeconds));
    }

    public function isOverdue(?string $dueAt, ?\DateTimeImmutable $now = null): bool
    {
        if ($dueAt === null || trim($dueAt) === '') {
            return false;
        }
        try {
            $due = new \DateTimeImmutable($dueAt, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return false;
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $due->getTimestamp() <= $now->getTimestamp();
    }

    private function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return '<1m';
        }
        foreach (self::UNITS as $unit) {
            if ($seconds >= $unit['seconds']) {
                return (string) intdiv($seconds, $unit['seconds']) . $unit['label'];
            }
        }
        return '<1m';
    }
}
