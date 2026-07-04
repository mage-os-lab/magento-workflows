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
    public const MODE = 'mode';
    public const CONTEXT = 'context';
    public const CHAIN_DEPTH = 'chain_depth';
    public const CURRENT_STEP = 'current_step';
    public const WAITING_EVENT = 'waiting_event';
    public const ORIGIN_UUID = 'origin_uuid';
    public const TRIGGERED_AT = 'triggered_at';
    public const COMPLETED_AT = 'completed_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Execution mode: a normal live/shadow execution vs a persisted dry-run
     * preview. NOT a side-effect predicate — a mode=live row under a
     * shadow-status workflow still ran simulated (docs/discovery/dry-run.md §6).
     */
    public const MODE_LIVE = 'live';
    public const MODE_DRY_RUN = 'dry_run';

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
     * Execution mode (live|dry_run); defaults to live
     */
    public function getMode(): string;

    public function setMode(string $mode): self;

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

    /**
     * Async-events trace UUID of the event that caused this execution, stamped
     * on fan-out children from the trigger payload's origin.trace_uuid; null for
     * ordinary executions (F1). Correlates every child of one source event in
     * the grid's "caused by" filter.
     */
    public function getOriginUuid(): ?string;

    public function setOriginUuid(?string $originUuid): self;
}
