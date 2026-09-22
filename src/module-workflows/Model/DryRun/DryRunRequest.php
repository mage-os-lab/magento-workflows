<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

/**
 * The immutable input to a dry-run: the definition being previewed (saved or
 * unsaved), its root conditions, and the subject — either a real entity
 * (type + id, hydrated) or a synthetic trigger payload (CI / snapshot-only
 * fidelity, no hydration). Workflow id/name are metadata for the trace header;
 * id is present only for saved-workflow runs.
 */
class DryRunRequest
{
    /**
     * @param array|null $triggerPayload synthetic payload; when set the run is
     *        snapshot-only and $entityId is derived from it
     */
    public function __construct(
        private readonly string $definitionJson,
        private readonly ?string $conditionsSerialized,
        private readonly string $entityType,
        private readonly ?int $entityId = null,
        private readonly ?array $triggerPayload = null,
        private readonly ?int $workflowId = null,
        private readonly string $workflowName = '',
        private readonly ?string $fanOut = null
    ) {
    }

    public function getDefinitionJson(): string
    {
        return $this->definitionJson;
    }

    public function getConditionsSerialized(): ?string
    {
        return $this->conditionsSerialized;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getTriggerPayload(): ?array
    {
        return $this->triggerPayload;
    }

    public function isSynthetic(): bool
    {
        return $this->triggerPayload !== null;
    }

    public function getWorkflowId(): ?int
    {
        return $this->workflowId;
    }

    public function getWorkflowName(): string
    {
        return $this->workflowName;
    }

    /**
     * Raw fan_out clause JSON ({relation, cap}) of the workflow being previewed,
     * or null when it does not fan out. Drives the fan-out preview node (04).
     */
    public function getFanOut(): ?string
    {
        return $this->fanOut;
    }
}
