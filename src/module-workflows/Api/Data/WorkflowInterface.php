<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

interface WorkflowInterface
{
    public const WORKFLOW_ID = 'workflow_id';
    public const NAME = 'name';
    public const STATUS = 'status';
    public const TRIGGER_TYPE = 'trigger_type';
    public const TRIGGER_REF = 'trigger_ref';
    public const ENTITY_TYPE = 'entity_type';
    public const CONDITIONS_SERIALIZED = 'conditions_serialized';
    public const DEFINITION = 'definition';
    public const AGGREGATION = 'aggregation';
    public const VERSION = 'version';
    public const LOOP_GUARD_DEPTH = 'loop_guard_depth';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;
    public const STATUS_SHADOW = 2;
    public const STATUS_SUSPENDED = 3;

    public const TRIGGER_TYPE_EVENT = 'event';
    public const TRIGGER_TYPE_SCHEDULE = 'schedule';
    public const TRIGGER_TYPE_MANUAL = 'manual';

    public function getWorkflowId(): ?int;

    public function setWorkflowId(int $workflowId): self;

    public function getName(): string;

    public function setName(string $name): self;

    public function getStatus(): int;

    public function setStatus(int $status): self;

    public function getTriggerType(): string;

    public function setTriggerType(string $triggerType): self;

    public function getTriggerRef(): string;

    public function setTriggerRef(string $triggerRef): self;

    public function getEntityType(): string;

    public function setEntityType(string $entityType): self;

    public function getConditionsSerialized(): ?string;

    public function setConditionsSerialized(?string $conditions): self;

    /**
     * Definition step-graph JSON (see docs/04-definition-format.md)
     */
    public function getDefinition(): string;

    public function setDefinition(string $definition): self;

    /**
     * Aggregation config JSON (batch aggregation, 05). Null = a per-entity
     * workflow; non-null = an aggregated workflow (the "kind" derivation).
     */
    public function getAggregation(): ?string;

    public function setAggregation(?string $aggregation): self;

    public function getVersion(): int;

    public function setVersion(int $version): self;

    public function getLoopGuardDepth(): int;

    public function setLoopGuardDepth(int $depth): self;

    /**
     * @return int[]
     */
    public function getWebsiteIds(): array;

    /**
     * @param int[] $websiteIds
     */
    public function setWebsiteIds(array $websiteIds): self;
}
