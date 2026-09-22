<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Test\Unit\Stub;

use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Minimal in-memory WorkflowInterface stand-in for unit tests (Magento's
 * generated data models are unavailable outside an install): getters back a
 * data bag, setters mutate it. No ORM, no resource model.
 *
 * Two construction styles, matching the suites that share this stub:
 * an int/null seeds workflow_id (fan-out suites), an array seeds the whole
 * data bag (aggregation/scheduler suites).
 */
class WorkflowStub implements WorkflowInterface
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed>|int|null $seed
     */
    public function __construct(array|int|null $seed = 1)
    {
        $this->data = is_array($seed) ? $seed : ['workflow_id' => $seed];
    }

    public function getWorkflowId(): ?int
    {
        return isset($this->data['workflow_id']) ? (int) $this->data['workflow_id'] : null;
    }

    public function setWorkflowId(int $workflowId): self
    {
        $this->data['workflow_id'] = $workflowId;
        return $this;
    }

    public function getName(): string
    {
        return (string) ($this->data['name'] ?? '');
    }

    public function setName(string $name): self
    {
        $this->data['name'] = $name;
        return $this;
    }

    public function getStatus(): int
    {
        return (int) ($this->data['status'] ?? self::STATUS_ENABLED);
    }

    public function setStatus(int $status): self
    {
        $this->data['status'] = $status;
        return $this;
    }

    public function getTriggerType(): string
    {
        return (string) ($this->data['trigger_type'] ?? self::TRIGGER_TYPE_EVENT);
    }

    public function setTriggerType(string $triggerType): self
    {
        $this->data['trigger_type'] = $triggerType;
        return $this;
    }

    public function getTriggerRef(): string
    {
        return (string) ($this->data['trigger_ref'] ?? '');
    }

    public function setTriggerRef(string $triggerRef): self
    {
        $this->data['trigger_ref'] = $triggerRef;
        return $this;
    }

    public function getEntityType(): string
    {
        return (string) ($this->data['entity_type'] ?? '');
    }

    public function setEntityType(string $entityType): self
    {
        $this->data['entity_type'] = $entityType;
        return $this;
    }

    public function getConditionsSerialized(): ?string
    {
        return $this->data['conditions_serialized'] ?? null;
    }

    public function setConditionsSerialized(?string $conditions): self
    {
        $this->data['conditions_serialized'] = $conditions;
        return $this;
    }

    public function getDefinition(): string
    {
        return (string) ($this->data['definition'] ?? '{"schema":3,"steps":[],"entry":null}');
    }

    public function setDefinition(string $definition): self
    {
        $this->data['definition'] = $definition;
        return $this;
    }

    public function getAggregation(): ?string
    {
        return $this->data['aggregation'] ?? null;
    }

    public function setAggregation(?string $aggregation): self
    {
        $this->data['aggregation'] = $aggregation;
        return $this;
    }

    public function getVersion(): int
    {
        return (int) ($this->data['version'] ?? 1);
    }

    public function setVersion(int $version): self
    {
        $this->data['version'] = $version;
        return $this;
    }

    public function getLoopGuardDepth(): int
    {
        return (int) ($this->data['loop_guard_depth'] ?? 1);
    }

    public function setLoopGuardDepth(int $depth): self
    {
        $this->data['loop_guard_depth'] = $depth;
        return $this;
    }

    public function getFanOut(): ?string
    {
        return $this->data['fan_out'] ?? null;
    }

    public function setFanOut(?string $fanOut): self
    {
        $this->data['fan_out'] = $fanOut === '' ? null : $fanOut;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getWebsiteIds(): array
    {
        return array_values(array_map('intval', $this->data['website_ids'] ?? []));
    }

    /**
     * @inheritDoc
     */
    public function setWebsiteIds(array $websiteIds): self
    {
        $this->data['website_ids'] = $websiteIds;
        return $this;
    }
}
