<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Api\Data;

/**
 * Approval task record (docs/discovery/approval-gate.md §3). The int approval_id
 * is never exposed over the API — the uuid is the sole external handle.
 */
interface ApprovalInterface
{
    public const APPROVAL_ID = 'approval_id';
    public const UUID = 'uuid';
    public const EXECUTION_ID = 'execution_id';
    public const STEP_KEY = 'step_key';
    public const WORKFLOW_ID = 'workflow_id';
    public const ENTITY_TYPE = 'entity_type';
    public const ENTITY_ID = 'entity_id';
    public const TITLE = 'title';
    public const INSTRUCTIONS = 'instructions';
    public const ASSIGNEE_ROLE = 'assignee_role';
    public const STATUS = 'status';
    public const DUE_AT = 'due_at';
    public const DECIDED_BY_TYPE = 'decided_by_type';
    public const DECIDED_BY_ID = 'decided_by_id';
    public const DECISION_NOTE = 'decision_note';
    public const DECISION_PAYLOAD = 'decision_payload';
    public const CREATED_AT = 'created_at';
    public const DECIDED_AT = 'decided_at';

    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ORPHANED = 'orphaned';

    public const DECIDER_ADMIN = 'admin';
    public const DECIDER_INTEGRATION = 'integration';

    public function getUuid(): string;

    public function setUuid(string $uuid): self;

    public function getExecutionId(): int;

    public function setExecutionId(int $executionId): self;

    public function getStepKey(): string;

    public function setStepKey(string $stepKey): self;

    public function getWorkflowId(): int;

    public function setWorkflowId(int $workflowId): self;

    public function getEntityType(): string;

    public function setEntityType(string $entityType): self;

    public function getEntityId(): int;

    public function setEntityId(int $entityId): self;

    public function getTitle(): string;

    public function setTitle(string $title): self;

    public function getInstructions(): ?string;

    public function setInstructions(?string $instructions): self;

    public function getAssigneeRole(): ?string;

    public function setAssigneeRole(?string $assigneeRole): self;

    public function getStatus(): string;

    public function setStatus(string $status): self;

    public function getDueAt(): ?string;

    public function setDueAt(?string $dueAt): self;

    public function getDecidedByType(): ?string;

    public function setDecidedByType(?string $decidedByType): self;

    public function getDecidedById(): ?string;

    public function setDecidedById(?string $decidedById): self;

    public function getDecisionNote(): ?string;

    public function setDecisionNote(?string $decisionNote): self;

    public function getDecisionPayload(): ?string;

    public function setDecisionPayload(?string $decisionPayload): self;

    public function getCreatedAt(): ?string;

    public function setCreatedAt(?string $createdAt): self;

    public function getDecidedAt(): ?string;

    public function setDecidedAt(?string $decidedAt): self;
}
