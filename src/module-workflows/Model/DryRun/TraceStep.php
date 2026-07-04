<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

/**
 * One visited step in a dry-run trace (pinned shape, docs/discovery/
 * implementation/03-dry-run.md). The trace is a flat ordered list; a step's
 * position in the graph's fan-out is encoded by {@see getPathIds()} rather
 * than by nesting — a rejoined shared tail is emitted once and carries every
 * contributing path id, so renderers rebuild the tree from that.
 *
 * The DTO is intentionally mutable in two narrow ways: path ids accumulate as
 * fan-out paths reconverge on it, and notes append as the walker annotates.
 * Everything else is fixed at construction.
 */
class TraceStep
{
    /** @var string[] */
    private array $pathIds = [];

    /** @var string[] */
    private array $notes;

    /**
     * @param array $config interpolated config, secrets already redacted
     * @param array{serialized: string, result: bool, revalidated: bool}|null $condition
     * @param array{resume_at: string, clamped: bool, timezone: string}|null $timing
     * @param string[] $notes
     */
    public function __construct(
        private readonly string $stepKey,
        private readonly string $type,
        private TraceStepStatus $status,
        private readonly ?string $would = null,
        private readonly array $config = [],
        private readonly ?array $condition = null,
        private readonly ?array $timing = null,
        private readonly ?string $edgeTaken = null,
        array $notes = []
    ) {
        $this->notes = array_values($notes);
    }

    public function getStepKey(): string
    {
        return $this->stepKey;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): TraceStepStatus
    {
        return $this->status;
    }

    public function setStatus(TraceStepStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * @return string[]
     */
    public function getPathIds(): array
    {
        return $this->pathIds;
    }

    public function addPathId(string $pathId): void
    {
        if (!in_array($pathId, $this->pathIds, true)) {
            $this->pathIds[] = $pathId;
        }
    }

    public function getWould(): ?string
    {
        return $this->would;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @return array{serialized: string, result: bool, revalidated: bool}|null
     */
    public function getCondition(): ?array
    {
        return $this->condition;
    }

    /**
     * @return array{resume_at: string, clamped: bool, timezone: string}|null
     */
    public function getTiming(): ?array
    {
        return $this->timing;
    }

    public function getEdgeTaken(): ?string
    {
        return $this->edgeTaken;
    }

    /**
     * @return string[]
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    public function addNote(string $note): void
    {
        $this->notes[] = $note;
    }

    public function toArray(): array
    {
        return [
            'step_key' => $this->stepKey,
            'type' => $this->type,
            'status' => $this->status->value,
            'path_ids' => $this->pathIds,
            'would' => $this->would,
            'config' => $this->config,
            'condition' => $this->condition,
            'timing' => $this->timing,
            'edge_taken' => $this->edgeTaken,
            'notes' => $this->notes,
        ];
    }
}
