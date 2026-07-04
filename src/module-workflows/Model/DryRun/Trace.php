<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

use MageOS\Workflows\Api\Data\ValidationMessageInterface;

/**
 * The result of a dry-run (pinned shape, docs/discovery/implementation/
 * 03-dry-run.md): the validation findings the definition produced under the
 * dry-run check subset, and — when the graph was sound enough to walk — the
 * flat ordered list of visited steps.
 *
 * A broken graph (or a missing entity) yields a Trace with findings and an
 * empty step list: dry-run returns validation results, never a fabricated
 * trace. A workflow whose root conditions do not match the entity yields an
 * empty step list flagged {@see isSkipped()}.
 */
class Trace
{
    /**
     * @param array{id?: int, name?: string} $workflow
     * @param array{type: string, id?: ?int} $entity
     * @param ValidationMessageInterface[] $validation
     * @param TraceStep[] $steps
     */
    /**
     * @param array{id?: int, name?: string} $workflow
     * @param array{type: string, id?: ?int} $entity
     * @param ValidationMessageInterface[] $validation
     * @param TraceStep[] $steps
     * @param array<string, mixed>|null $fanOut fan-out preview node (04): what a
     *        source event would fan out to; null for non-fan-out workflows
     */
    public function __construct(
        private readonly array $workflow,
        private readonly array $entity,
        private readonly array $validation = [],
        private readonly array $steps = [],
        private readonly bool $skipped = false,
        private readonly bool $truncated = false,
        private readonly ?array $fanOut = null
    ) {
    }

    /**
     * @return array{id?: int, name?: string}
     */
    public function getWorkflow(): array
    {
        return $this->workflow;
    }

    /**
     * @return array{type: string, id?: ?int}
     */
    public function getEntity(): array
    {
        return $this->entity;
    }

    /**
     * @return ValidationMessageInterface[]
     */
    public function getValidation(): array
    {
        return $this->validation;
    }

    /**
     * @return TraceStep[]
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * True when a blocking validation finding stopped the walk before it began.
     */
    public function hasErrors(): bool
    {
        foreach ($this->validation as $message) {
            if ($message->getSeverity() === ValidationMessageInterface::SEVERITY_ERROR) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the workflow's root conditions did not match the entity, so no
     * step would run at all.
     */
    public function isSkipped(): bool
    {
        return $this->skipped;
    }

    /**
     * True when the distinct-step-visit cap stopped the walk before every
     * reachable step was rendered.
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Fan-out preview node (04): "would dispatch N executions (first 3: …)" for
     * a source event, or null when the workflow does not fan out.
     *
     * @return array<string, mixed>|null
     */
    public function getFanOut(): ?array
    {
        return $this->fanOut;
    }

    public function toArray(): array
    {
        return [
            'workflow' => $this->workflow,
            'entity' => $this->entity,
            'fan_out' => $this->fanOut,
            'validation' => array_map(
                static fn (ValidationMessageInterface $m): array => [
                    'severity' => $m->getSeverity(),
                    'code' => $m->getCode(),
                    'message' => $m->getMessage(),
                    'step_key' => $m->getStepKey(),
                    'edge' => $m->getEdge(),
                ],
                $this->validation
            ),
            'steps' => array_map(static fn (TraceStep $s): array => $s->toArray(), $this->steps),
            'skipped' => $this->skipped,
            'truncated' => $this->truncated,
        ];
    }
}
