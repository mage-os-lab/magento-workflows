<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

/**
 * The routing-only half of the dry-run walk: it owns traversal order, path-id
 * assignment, fan-out, rejoin detection, and the distinct-step-visit cap — and
 * nothing about what a step means (that is the {@see Walker}). Keeping the two
 * apart is the divergence control from discovery §5: all the graph semantics
 * live next to the shared edge helper, and the bookkeeping is testable on its
 * own.
 *
 * Traversal is depth-first pre-order so a reader sees a whole path before the
 * next fan-out sibling. The cap counts DISTINCT steps, not leaf paths
 * (discovery §4): waits and failed branch evaluations fan out to every edge,
 * and their branches commonly reconverge on a shared tail; that tail is
 * emitted once and accumulates every path id that reaches it, so nested waits
 * cannot multiply the trace.
 *
 * Usage is a pull loop driven by the Walker:
 *
 *     $explorer->seed($entryKey);
 *     while (($visit = $explorer->next()) !== null) {
 *         [$step, $edges, $stops] = $walker->evaluate($visit);
 *         $explorer->place($visit, $step, $edges, $stops);
 *     }
 *     $steps = $explorer->getSteps();
 */
class PathExplorer
{
    /**
     * Pending frontier: each entry is [stepKey, pathId, pastProductionStop].
     *
     * @var array<int, array{0: string, 1: string, 2: bool}>
     */
    private array $stack = [];

    /**
     * step key => index into $steps (rejoin lookups + path-id merges)
     *
     * @var array<string, int>
     */
    private array $index = [];

    /** @var TraceStep[] */
    private array $steps = [];

    private int $pathCounter = 0;

    private bool $truncated = false;

    public function __construct(
        private readonly int $maxDistinctSteps = self::DEFAULT_MAX_DISTINCT_STEPS
    ) {
    }

    public const DEFAULT_MAX_DISTINCT_STEPS = 200;

    public function seed(string $entryKey): void
    {
        $this->stack[] = [$entryKey, $this->nextPathId(), false];
    }

    /**
     * Next step to evaluate, or null when the frontier is drained or the cap is
     * hit. Steps already visited are silently merged (rejoin) and skipped.
     */
    public function next(): ?Visit
    {
        while ($this->stack !== []) {
            [$stepKey, $pathId, $pastStop] = array_pop($this->stack);

            if (isset($this->index[$stepKey])) {
                // Rejoin: the shared tail is already rendered; just record that
                // this path also reaches it, and do not re-walk its subtree.
                $this->steps[$this->index[$stepKey]]->addPathId($pathId);
                continue;
            }

            if (count($this->steps) >= $this->maxDistinctSteps) {
                $this->truncated = true;
                return null;
            }

            return new Visit($stepKey, $pathId, $pastStop);
        }
        return null;
    }

    /**
     * Record the evaluated step and schedule the edges the walker chose to
     * follow. Non-null targets only; a single follow edge keeps the current
     * path id, a fan-out mints a fresh id per edge.
     *
     * @param array<int, array{label: string, target: ?string}> $followEdges
     * @param bool $productionStops true when production would terminate after
     *        this step, so everything downstream is dry-run-only
     */
    public function place(Visit $visit, TraceStep $step, array $followEdges, bool $productionStops): void
    {
        $step->addPathId($visit->getPathId());
        $this->index[$visit->getStepKey()] = count($this->steps);
        $this->steps[] = $step;

        $targets = array_values(array_filter(
            $followEdges,
            static fn (array $edge): bool => $edge['target'] !== null
        ));
        if ($targets === []) {
            return;
        }

        $pastStop = $visit->isPastProductionStop() || $productionStops;
        $fanOut = count($targets) > 1;

        // Reverse so the first declared edge is popped (and thus rendered) first
        // under the LIFO stack — depth-first pre-order.
        foreach (array_reverse($targets) as $edge) {
            $pathId = $fanOut ? $this->nextPathId() : $visit->getPathId();
            $this->stack[] = [(string) $edge['target'], $pathId, $pastStop];
        }
    }

    /**
     * @return TraceStep[]
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    private function nextPathId(): string
    {
        return 'p' . (++$this->pathCounter);
    }
}
