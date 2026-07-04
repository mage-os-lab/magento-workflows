<?php
declare(strict_types=1);

namespace MageOS\WorkflowsScheduler\Test\Unit\Stub;

use MageOS\Workflows\Api\Data\WorkflowInterface;

/**
 * Lightweight WorkflowInterface for scheduler unit tests: getters back a data
 * bag, setters mutate it. No ORM, no resource model.
 */
class WorkflowStub implements WorkflowInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function getWorkflowId(): ?int
    {
        return isset($this->data['workflow_id']) ? (int) $this->data['workflow_id'] : null;
    }

    public function setWorkflowId(int $workflowId): WorkflowInterface
    {
        $this->data['workflow_id'] = $workflowId;
        return $this;
    }

    public function getName(): string
    {
        return (string) ($this->data['name'] ?? '');
    }

    public function setName(string $name): WorkflowInterface
    {
        $this->data['name'] = $name;
        return $this;
    }

    public function getStatus(): int
    {
        return (int) ($this->data['status'] ?? 0);
    }

    public function setStatus(int $status): WorkflowInterface
    {
        $this->data['status'] = $status;
        return $this;
    }

    public function getTriggerType(): string
    {
        return (string) ($this->data['trigger_type'] ?? '');
    }

    public function setTriggerType(string $triggerType): WorkflowInterface
    {
        $this->data['trigger_type'] = $triggerType;
        return $this;
    }

    public function getTriggerRef(): string
    {
        return (string) ($this->data['trigger_ref'] ?? '');
    }

    public function setTriggerRef(string $triggerRef): WorkflowInterface
    {
        $this->data['trigger_ref'] = $triggerRef;
        return $this;
    }

    public function getEntityType(): string
    {
        return (string) ($this->data['entity_type'] ?? '');
    }

    public function setEntityType(string $entityType): WorkflowInterface
    {
        $this->data['entity_type'] = $entityType;
        return $this;
    }

    public function getConditionsSerialized(): ?string
    {
        return $this->data['conditions_serialized'] ?? null;
    }

    public function setConditionsSerialized(?string $conditions): WorkflowInterface
    {
        $this->data['conditions_serialized'] = $conditions;
        return $this;
    }

    public function getDefinition(): string
    {
        return (string) ($this->data['definition'] ?? '');
    }

    public function setDefinition(string $definition): WorkflowInterface
    {
        $this->data['definition'] = $definition;
        return $this;
    }

    public function getFanOut(): ?string
    {
        return $this->data['fan_out'] ?? null;
    }

    public function setFanOut(?string $fanOut): WorkflowInterface
    {
        $this->data['fan_out'] = $fanOut === '' ? null : $fanOut;
        return $this;
    }

    public function getAggregation(): ?string
    {
        return $this->data['aggregation'] ?? null;
    }

    public function setAggregation(?string $aggregation): WorkflowInterface
    {
        $this->data['aggregation'] = $aggregation;
        return $this;
    }

    public function getVersion(): int
    {
        return (int) ($this->data['version'] ?? 1);
    }

    public function setVersion(int $version): WorkflowInterface
    {
        $this->data['version'] = $version;
        return $this;
    }

    public function getLoopGuardDepth(): int
    {
        return (int) ($this->data['loop_guard_depth'] ?? 1);
    }

    public function setLoopGuardDepth(int $depth): WorkflowInterface
    {
        $this->data['loop_guard_depth'] = $depth;
        return $this;
    }

    public function getWebsiteIds(): array
    {
        return array_map('intval', $this->data['website_ids'] ?? []);
    }

    public function setWebsiteIds(array $websiteIds): WorkflowInterface
    {
        $this->data['website_ids'] = $websiteIds;
        return $this;
    }
}
