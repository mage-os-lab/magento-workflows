<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\Webapi;

use MageOS\Workflows\Api\Data\DryRunTraceStepInterface;
use MageOS\Workflows\Model\DryRun\TraceStep;

/**
 * Immutable webapi DTO for one dry-run trace step. Flattens a {@see TraceStep},
 * JSON-encoding its nested config/condition/timing for transport.
 */
class DryRunTraceStep implements DryRunTraceStepInterface
{
    public function __construct(
        private readonly string $stepKey,
        private readonly string $type,
        private readonly string $status,
        private readonly array $pathIds,
        private readonly ?string $would,
        private readonly ?string $edgeTaken,
        private readonly array $notes,
        private readonly string $config,
        private readonly ?string $condition,
        private readonly ?string $timing
    ) {
    }

    public static function fromTraceStep(TraceStep $step): self
    {
        return new self(
            $step->getStepKey(),
            $step->getType(),
            $step->getStatus()->value,
            $step->getPathIds(),
            $step->getWould(),
            $step->getEdgeTaken(),
            $step->getNotes(),
            (string) json_encode($step->getConfig(), JSON_UNESCAPED_SLASHES),
            $step->getCondition() !== null ? (string) json_encode($step->getCondition(), JSON_UNESCAPED_SLASHES) : null,
            $step->getTiming() !== null ? (string) json_encode($step->getTiming(), JSON_UNESCAPED_SLASHES) : null
        );
    }

    public function getStepKey(): string
    {
        return $this->stepKey;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function getPathIds(): array
    {
        return $this->pathIds;
    }

    public function getWould(): ?string
    {
        return $this->would;
    }

    public function getEdgeTaken(): ?string
    {
        return $this->edgeTaken;
    }

    /**
     * @inheritDoc
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    public function getConfig(): string
    {
        return $this->config;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }

    public function getTiming(): ?string
    {
        return $this->timing;
    }
}
