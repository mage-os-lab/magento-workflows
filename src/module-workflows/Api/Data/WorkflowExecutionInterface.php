<?php
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

interface WorkflowExecutionInterface
{
    public const EXECUTION_ID = 'execution_id';
    public const UUID = 'uuid';
    public const WORKFLOW_ID = 'workflow_id';
    public const WORKFLOW_VERSION = 'workflow_version';
    public const DEFINITION_SNAPSHOT = 'definition_snapshot';
    public const ENTITY_ID = 'entity_id';
    public const STORE_ID = 'store_id';
    public const STATUS = 'status';
    public const CONTEXT = 'context';
    public const CHAIN_DEPTH = 'chain_depth';
    public const CURRENT_STEP = 'current_step';
    public const WAITING_EVENT = 'waiting_event';
    public const TRIGGERED_AT = 'triggered_at';
    public const COMPLETED_AT = 'completed_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function getExecutionId(): ?int;

    public function setExecutionId(int $executionId): self;

    public function getUuid(): string;

    public function setUuid(string $uuid): self;

    public function getWorkflowId(): int;

    public function setWorkflowId(int $workflowId): self;

    public function getWorkflowVersion(): int;

    public function setWorkflowVersion(int $version): self;

    /**
     * Full definition JSON pinned at trigger time; resumption never depends on the live definition
     */
    public function getDefinitionSnapshot(): string;

    public function setDefinitionSnapshot(string $definition): self;

    public function getEntityId(): int;

    public function setEntityId(int $entityId): self;

    public function getStoreId(): int;

    public function setStoreId(int $storeId): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    /**
     * Context bag JSON: {trigger: {...}, steps: {...}, workflow: {...}}
     */
    public function getContext(): ?string;

    public function setContext(?string $context): self;

    public function getChainDepth(): int;

    public function setChainDepth(int $chainDepth): self;

    public function getCurrentStep(): ?string;

    public function setCurrentStep(?string $stepKey): self;

    /**
     * Event name a wait step parked this execution on (null outside waits)
     */
    public function getWaitingEvent(): ?string;

    public function setWaitingEvent(?string $event): self;
}
