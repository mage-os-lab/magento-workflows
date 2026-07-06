<?php
declare(strict_types=1);

namespace MageOS\WorkflowsApprovals\Test\Unit\Stub;

use MageOS\WorkflowsApprovals\Api\Data\ApprovalInterface;

/**
 * Minimal in-memory ApprovalInterface double — MageOS\WorkflowsApprovals\Model\Approval
 * extends Magento\Framework\Model\AbstractModel, which has no standalone-runner
 * shim, so tests that only need the data contract use this instead (same
 * posture as MageOS\Workflows\Test\Unit\Stub\WorkflowExecutionStub for the
 * execution interface).
 */
class FakeApproval implements ApprovalInterface
{
    private string $uuid = '';
    private int $executionId = 0;
    private string $stepKey = '';
    private int $workflowId = 0;
    private string $entityType = '';
    private int $entityId = 0;
    private string $title = '';
    private ?string $instructions = null;
    private ?string $assigneeRole = null;
    private string $status = self::STATUS_OPEN;
    private ?string $dueAt = null;
    private ?string $decidedByType = null;
    private ?string $decidedById = null;
    private ?string $decisionNote = null;
    private ?string $decisionPayload = null;
    private ?string $createdAt = null;
    private ?string $decidedAt = null;

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function getExecutionId(): int
    {
        return $this->executionId;
    }

    public function setExecutionId(int $executionId): self
    {
        $this->executionId = $executionId;
        return $this;
    }

    public function getStepKey(): string
    {
        return $this->stepKey;
    }

    public function setStepKey(string $stepKey): self
    {
        $this->stepKey = $stepKey;
        return $this;
    }

    public function getWorkflowId(): int
    {
        return $this->workflowId;
    }

    public function setWorkflowId(int $workflowId): self
    {
        $this->workflowId = $workflowId;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function setInstructions(?string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function getAssigneeRole(): ?string
    {
        return $this->assigneeRole;
    }

    public function setAssigneeRole(?string $assigneeRole): self
    {
        $this->assigneeRole = $assigneeRole;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDueAt(): ?string
    {
        return $this->dueAt;
    }

    public function setDueAt(?string $dueAt): self
    {
        $this->dueAt = $dueAt;
        return $this;
    }

    public function getDecidedByType(): ?string
    {
        return $this->decidedByType;
    }

    public function setDecidedByType(?string $decidedByType): self
    {
        $this->decidedByType = $decidedByType;
        return $this;
    }

    public function getDecidedById(): ?string
    {
        return $this->decidedById;
    }

    public function setDecidedById(?string $decidedById): self
    {
        $this->decidedById = $decidedById;
        return $this;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function setDecisionNote(?string $decisionNote): self
    {
        $this->decisionNote = $decisionNote;
        return $this;
    }

    public function getDecisionPayload(): ?string
    {
        return $this->decisionPayload;
    }

    public function setDecisionPayload(?string $decisionPayload): self
    {
        $this->decisionPayload = $decisionPayload;
        return $this;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?string $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getDecidedAt(): ?string
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?string $decidedAt): self
    {
        $this->decidedAt = $decidedAt;
        return $this;
    }
}
