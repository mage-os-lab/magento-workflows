<?php
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Model\DryRun;

use MageOS\Workflows\Model\DryRun\PathExplorer;
use MageOS\Workflows\Model\DryRun\TraceStep;
use MageOS\Workflows\Model\DryRun\TraceStepStatus;
use PHPUnit\Framework\TestCase;

/**
 * The routing bookkeeping in isolation: distinct-step-visit cap and rejoin
 * dedupe, independent of any step semantics.
 */
class PathExplorerTest extends TestCase
{
    private function step(string $key): TraceStep
    {
        return new TraceStep($key, 'stop', TraceStepStatus::WOULD_RUN);
    }

    public function testDistinctVisitCapTruncatesWalk(): void
    {
        $explorer = new PathExplorer(3);
        $explorer->seed('s1');

        $n = 1;
        while (($visit = $explorer->next()) !== null) {
            $next = $n < 10 ? 's' . ($n + 1) : null;
            $explorer->place(
                $visit,
                $this->step($visit->getStepKey()),
                [['label' => 'next', 'target' => $next]],
                false
            );
            $n++;
        }

        $this->assertCount(3, $explorer->getSteps());
        $this->assertTrue($explorer->isTruncated());
    }

    public function testUncappedLinearWalkIsNotTruncated(): void
    {
        $explorer = new PathExplorer(10);
        $explorer->seed('s1');
        $n = 1;
        while (($visit = $explorer->next()) !== null) {
            $next = $n < 3 ? 's' . ($n + 1) : null;
            $explorer->place($visit, $this->step($visit->getStepKey()), [['label' => 'next', 'target' => $next]], false);
            $n++;
        }
        $this->assertCount(3, $explorer->getSteps());
        $this->assertFalse($explorer->isTruncated());
    }

    public function testRejoinRendersSharedTailOnceWithAllPathIds(): void
    {
        $explorer = new PathExplorer(10);
        $explorer->seed('fork');

        // fork fans out to the same 'tail' twice (both edges reconverge).
        $visit = $explorer->next();
        $this->assertNotNull($visit);
        $explorer->place(
            $visit,
            $this->step('fork'),
            [
                ['label' => 'a', 'target' => 'tail'],
                ['label' => 'b', 'target' => 'tail'],
            ],
            false
        );

        // 'tail' visited once, then the rejoin is merged, not re-emitted.
        $tailVisit = $explorer->next();
        $this->assertNotNull($tailVisit);
        $this->assertSame('tail', $tailVisit->getStepKey());
        $explorer->place($tailVisit, $this->step('tail'), [], false);

        $this->assertNull($explorer->next());

        $steps = $explorer->getSteps();
        $this->assertCount(2, $steps);
        // Two fan-out paths both recorded on the single shared tail.
        $this->assertCount(2, $steps[1]->getPathIds());
    }

    public function testLinearChainKeepsOnePathId(): void
    {
        $explorer = new PathExplorer(10);
        $explorer->seed('s1');
        $v1 = $explorer->next();
        $explorer->place($v1, $this->step('s1'), [['label' => 'next', 'target' => 's2']], false);
        $v2 = $explorer->next();
        $explorer->place($v2, $this->step('s2'), [['label' => 'next', 'target' => null]], false);

        $steps = $explorer->getSteps();
        $this->assertSame(['p1'], $steps[0]->getPathIds());
        $this->assertSame(['p1'], $steps[1]->getPathIds());
    }
}
