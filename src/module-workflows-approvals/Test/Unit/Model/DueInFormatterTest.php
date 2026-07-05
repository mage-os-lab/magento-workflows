<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Model;

use MageOS\WorkflowsApprovals\Model\DueInFormatter;
use PHPUnit\Framework\TestCase;

class DueInFormatterTest extends TestCase
{
    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-05 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testFormatsFutureDueDate(): void
    {
        $formatter = new DueInFormatter();
        $this->assertSame('Due in 2h', $formatter->format('2026-07-05 14:00:00', $this->now()));
    }

    public function testFormatsOverdue(): void
    {
        $formatter = new DueInFormatter();
        $this->assertSame('Overdue by 3h', $formatter->format('2026-07-05 09:00:00', $this->now()));
    }

    public function testFormatsDaysWhenFarOut(): void
    {
        $formatter = new DueInFormatter();
        $this->assertSame('Due in 2d', $formatter->format('2026-07-07 12:00:00', $this->now()));
    }

    public function testNullDueAtRendersDash(): void
    {
        $formatter = new DueInFormatter();
        $this->assertSame('—', $formatter->format(null, $this->now()));
    }

    public function testMalformedDueAtRendersDash(): void
    {
        $formatter = new DueInFormatter();
        $this->assertSame('—', $formatter->format('not-a-date', $this->now()));
    }

    public function testIsOverdueTrueAtOrPastDueTime(): void
    {
        $formatter = new DueInFormatter();
        $this->assertTrue($formatter->isOverdue('2026-07-05 12:00:00', $this->now()));
        $this->assertTrue($formatter->isOverdue('2026-07-05 09:00:00', $this->now()));
    }

    public function testIsOverdueFalseForFutureDueTime(): void
    {
        $formatter = new DueInFormatter();
        $this->assertFalse($formatter->isOverdue('2026-07-05 14:00:00', $this->now()));
    }

    public function testIsOverdueFalseForNullDueAt(): void
    {
        $formatter = new DueInFormatter();
        $this->assertFalse($formatter->isOverdue(null, $this->now()));
    }
}
